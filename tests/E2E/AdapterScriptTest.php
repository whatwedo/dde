<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the built-in entrypoint adapters against a filesystem layout that
 * mirrors the whatwedo symfony base image (Debian, runit, php-fpm8.4 binary,
 * nginx `user` directive in /etc/nginx/directive.d/*.conf).
 *
 * Runs inside a throwaway Debian container so the adapters execute on GNU sed
 * exactly like in production, and so `id -gn` resolves a real group. Fixtures
 * are built inside the container (the host temp dir is not Docker-shareable on
 * macOS); only the repo's adapter dir is mounted. Reproduces the gid-reuse case
 * (DDE_GID maps onto an existing differently-named group), which is why adapters
 * must resolve the group name instead of hardcoding `dde`.
 */
#[Group('e2e')]
final class AdapterScriptTest extends TestCase
{
    public function testAdaptersRewriteServicesToResolvedDdeUserAndGroup(): void
    {
        $adaptersDir = \dirname(__DIR__, 2).'/resources/adapters';

        $script = <<<'SH'
            set -e
            # Reproduce DDE_GID=20: the dde user joins the pre-existing gid-20
            # group (named `dialout` on Debian), exactly as the dde entrypoint
            # does in the real container. `id -gn dde` must then yield `dialout`,
            # never the literal `dde`, or php-fpm would fail on an unknown group.
            echo "dde:x:501:20:dde:/home/dde:/bin/sh" >> /etc/passwd

            # nginx adapter detects via the binary; stub it (slim has no nginx).
            printf '#!/bin/sh\n' > /usr/local/bin/nginx && chmod +x /usr/local/bin/nginx

            mkdir -p /fix/etc/nginx/directive.d /fix/etc/php/8.4/fpm/pool.d
            printf 'include /etc/nginx/directive.d/*.conf;\nevents {}\n' > /fix/etc/nginx/nginx.conf
            printf 'user app app;\n' > /fix/etc/nginx/directive.d/05-user.conf
            printf '[www]\nuser = app\ngroup = app\nlisten = /tmp/php-fpm.sock\nlisten.owner = app\nlisten.group = app\n' > /fix/etc/php/8.4/fpm/pool.d/www.conf

            export DDE_NGINX_CONF_ROOT=/fix/etc/nginx
            export DDE_PHP_FPM_POOL_ROOT=/fix/etc/php
            for adapter in /adapters/nginx.sh /adapters/php-fpm.sh; do
                . "$adapter"
                if type detect >/dev/null 2>&1 && detect; then
                    configure
                fi
                unset -f detect configure 2>/dev/null || true
            done

            echo "===NGINX==="
            cat /fix/etc/nginx/directive.d/05-user.conf
            echo "===FPM==="
            cat /fix/etc/php/8.4/fpm/pool.d/www.conf
            SH;

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $adaptersDir.':/adapters:ro',
            'debian:stable-slim',
            'sh', '-c', $script,
        ]);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("adapter run failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );

        $output = $process->getOutput();
        [$nginx, $fpm] = explode('===FPM===', explode('===NGINX===', $output, 2)[1], 2);

        $this->assertStringContainsString('user dde dialout;', $nginx, 'nginx worker user must become the dde user with its resolved group');
        $this->assertStringNotContainsString('user app', $nginx);

        $this->assertStringContainsString('user = dde', $fpm);
        $this->assertStringContainsString('group = dialout', $fpm);
        $this->assertStringContainsString('listen.owner = dde', $fpm);
        $this->assertStringContainsString('listen.group = dialout', $fpm);
        $this->assertStringNotContainsString('= app', $fpm);
    }

    /**
     * Regression: the whatwedo symfony base image (Alpine) ships its pool config
     * as /etc/php84/php-fpm.d/www.conf, which matched neither the Debian glob
     * for fpm/pool.d nor the Docker-official path. detect() returned 1,
     * configure() was skipped, php-fpm stayed `user = nginx` while the nginx
     * adapter had already moved nginx onto the dde user — the socket ownership
     * mismatch produced HTTP 502 (connect() to php-fpm.sock: Permission denied).
     */
    public function testPhpFpmAdapterRewritesWhatwedoBaseImagePoolLayout(): void
    {
        $adaptersDir = \dirname(__DIR__, 2).'/resources/adapters';

        $script = <<<'SH'
            set -e
            echo "dde:x:501:20:dde:/home/dde:/bin/sh" >> /etc/passwd

            # Alpine PHP layout: versioned config dir, pool config directly under
            # php-fpm.d (no fpm/pool.d nesting). Mirror the whatwedo www.conf which
            # ships user/group AND uncommented listen.owner/listen.group as nginx.
            mkdir -p /fix/etc/php84/php-fpm.d
            printf '[www]\nuser = nginx\ngroup = nginx\nlisten = /var/run/php-fpm.sock\nlisten.owner = nginx\nlisten.group = nginx\n' > /fix/etc/php84/php-fpm.d/www.conf

            export DDE_PHP_FPM_POOL_ROOT=/fix/etc/php
            . /adapters/php-fpm.sh
            if detect; then
                configure
            fi

            echo "===FPM==="
            cat /fix/etc/php84/php-fpm.d/www.conf
            SH;

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $adaptersDir.':/adapters:ro',
            'debian:stable-slim',
            'sh', '-c', $script,
        ]);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("adapter run failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );

        $fpm = explode('===FPM===', $process->getOutput(), 2)[1];

        $this->assertStringContainsString('user = dde', $fpm);
        $this->assertStringContainsString('group = dialout', $fpm);
        $this->assertStringContainsString('listen.owner = dde', $fpm);
        $this->assertStringContainsString('listen.group = dialout', $fpm);
        $this->assertStringNotContainsString('= nginx', $fpm);
    }

    /**
     * Regression: the official wordpress image (Debian) ships GNU shadow's
     * useradd/groupadd AND Debian's Perl adduser/addgroup side by side. The
     * entrypoint used to gate user creation on `command -v adduser` and then
     * feed it BusyBox flags (`-u -G -D`), which Debian's adduser rejects with an
     * "Option is ambiguous" usage dump, leaving the dde user uncreated (only a
     * raw /etc/passwd append rescued it). Real symptom: WordPress-based project
     * containers spewed the adduser/addgroup usage text into their container log
     * on every start. The fix prefers shadow's useradd/groupadd (stable flags on
     * every distro) and only falls back to the correct BusyBox / Debian dialects.
     */
    #[Group('e2e')]
    public function testEntrypointCreatesDdeUserOnDebianWithoutAdduserUsageDump(): void
    {
        $entrypoint = \dirname(__DIR__, 2).'/resources/entrypoint.sh';

        $process = new Process([
            'docker', 'run', '--rm', '-u', '0',
            '-e', 'DDE_UID=1000', '-e', 'DDE_GID=1000',
            '-v', $entrypoint.':/dde-entrypoint.sh:ro',
            'wordpress:latest',
            'sh', '-c', '/dde-entrypoint.sh true; echo "===ID==="; id dde',
        ]);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("entrypoint run failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );

        $id = explode('===ID===', $process->getOutput(), 2)[1];
        $this->assertStringContainsString('uid=1000(dde)', $id);
        $this->assertStringContainsString('gid=1000(dde)', $id);

        // The adduser/addgroup usage dump is the exact regression signature.
        $combined = $process->getOutput().$process->getErrorOutput();
        $this->assertStringNotContainsString('Option is ambiguous', $combined);
        $this->assertStringNotContainsString('adduser [--uid', $combined);
    }
}
