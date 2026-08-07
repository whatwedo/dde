<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Regression: v2.0.0 chown'd /var/lib/nginx/tmp to the dde user but left
     * /var/lib/nginx/ owned nginx:nginx 750 — the dde worker (run as "other")
     * had no traverse bit on the parent and could never reach the temp dir,
     * so large uploads returned HTTP 500 Permission denied.
     */
    public function testNginxAdapterGrantsTraverseBitOnVarLibNginx(): void
    {
        $adaptersDir = \dirname(__DIR__, 2).'/resources/adapters';

        $script = <<<'SH'
            set -e
            # Create nginx group/user and the dde user in the pre-existing gid-20 group.
            echo "nginx:x:101:" >> /etc/group
            echo "nginx:x:101:101:nginx:/var/lib/nginx:/sbin/nologin" >> /etc/passwd
            echo "dde:x:501:20:dde:/home/dde:/bin/sh" >> /etc/passwd

            # Stub nginx binary so detect() succeeds.
            printf '#!/bin/sh\n' > /usr/local/bin/nginx && chmod +x /usr/local/bin/nginx

            # Reproduce the Alpine base-image layout: parent nginx:nginx 750,
            # tmp already chown'd to dde by the v2.0.0 partial fix.
            mkdir -p /var/lib/nginx/tmp/client_body
            chown -R nginx:nginx /var/lib/nginx
            chmod 750 /var/lib/nginx
            chown dde:dialout /var/lib/nginx/tmp
            chmod 700 /var/lib/nginx/tmp

            # Minimal config dir so the sed pass in configure() has something to iterate.
            mkdir -p /fix/etc/nginx/directive.d
            printf 'user nginx nginx;\n' > /fix/etc/nginx/directive.d/05-user.conf

            export DDE_NGINX_CONF_ROOT=/fix/etc/nginx
            . /adapters/nginx.sh
            if detect; then configure; fi

            echo "===PERMS==="
            stat -c "%a" /var/lib/nginx
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

        $perms = trim(explode('===PERMS===', $process->getOutput(), 2)[1] ?? '');
        // 751 = 750 | o+x — others now have the traverse bit
        $this->assertSame('751', $perms, '/var/lib/nginx must have o+x so the dde worker can traverse into tmp/');
    }

    /**
     * Regression: the ca-trust adapter used to branch on trust-store directory
     * existence, in this order: /usr/local/share/ca-certificates first. That
     * directory exists on minimal Debian *without* the ca-certificates package
     * (so update-ca-certificates was absent and the CA was silently dropped),
     * and it also exists on openSUSE (whose update-ca-certificates reads only
     * /etc/pki/trust/anchors, so that branch shadowed the SUSE one and trusted
     * nothing). Both symptoms: in-container `curl https://<other>.test` failed
     * with a certificate error. The adapter now keys off the trust tooling and
     * installs ca-certificates on demand, so the mounted mkcert CA lands in the
     * consumed trust bundle on every supported base image.
     *
     * @param non-empty-string $consumedBundle path to the store the CA must reach,
     *                                          not the anchor dir the adapter writes to
     */
    #[Group('e2e')]
    #[DataProvider('caTrustImageProvider')]
    public function testCaTrustAdapterInstallsMkcertCaIntoConsumedTrustStore(string $image, string $consumedBundle): void
    {
        $adaptersDir = \dirname(__DIR__, 2).'/resources/adapters';

        // A throwaway self-signed CA; only its verbatim base64 body is used as a
        // marker that survives into the regenerated bundle.
        $caPem = <<<'PEM'
            -----BEGIN CERTIFICATE-----
            MIIDGzCCAgOgAwIBAgIUBAqXZM5zUi574Ap9gAoHRc7sE28wDQYJKoZIhvcNAQEL
            BQAwHTEbMBkGA1UEAwwSbWtjZXJ0IGRkZSB0ZXN0IENBMB4XDTI2MDcyMzA5MTgy
            NVoXDTM2MDcyMDA5MTgyNVowHTEbMBkGA1UEAwwSbWtjZXJ0IGRkZSB0ZXN0IENB
            MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuOlub4QE8vR5dH1j1+Fh
            m3bAASX4UaD8W5WDB3sQusWjDwiEd7m18rKbsZVCYLdRI8jzA9VrRcsJQJwTr/V/
            zaSyNXI0/Fzb10sYkClNxvJ5zIoQk0/Gvz/UYIEQnmUdEbh1LVWHvcXgixztf7Zu
            QVm+FIc8bJZ38XnlxJa5R0Ex8W/nHb/91NFkRnWI2fIpxnUxdtQqW33CvTlwlvUF
            0tWHKBJmqzc6mfON4KemDllUsI3bwJQZw6jk1WA6KXExdYz0IHhSNxb16lMAIXBu
            XwIwJJ0J6OrF2Vn6chNC7ARh/457dysdUfqwmEgtnzhhHgxwekeXyHrp3Kmam+Qu
            twIDAQABo1MwUTAdBgNVHQ4EFgQUcoZ8Ro/4SU+yL3+lMy/8bgqeReEwHwYDVR0j
            BBgwFoAUcoZ8Ro/4SU+yL3+lMy/8bgqeReEwDwYDVR0TAQH/BAUwAwEB/zANBgkq
            hkiG9w0BAQsFAAOCAQEANROOq23PdrzLoDaTzHVJV8jw6qTGWZI/gx7bBsFk5ZbK
            zukSfb8ROkqNEf9T7tteJ6YuZB0HqxZ2irHoDi9lyDHkAXHBUi0726Qf/R7+h+f5
            c3s90HWkN7tFelDKor8eWeG3Sc8/hZ4ZbcebUyBx5LgU+IBN30XR2xZPMUyyddsM
            RJi62WUQp2W4kBJVI+PczVBcEj3ja2wo53mrkDj3RzuDK1JqqvHZlgXg8yGV+VCL
            ZqdIqXvGCuHP0SwTJ5EvW9d0s9nUb+fTWMqNXHtoP1AMIKzTp1w8/nidlXURJfC6
            A7xCX14VlbD2rQ80cxNAHu3ge6p5K4gUeDDQcr9/HQ==
            -----END CERTIFICATE-----
            PEM;

        // A base64 line from the cert body — grepped for in the consumed bundle.
        $marker = 'MIIDGzCCAgOgAwIBAgIUBAqXZM5zUi574Ap9gAoHRc7sE28wDQYJKoZIhvcNAQEL';

        $script = sprintf(<<<'SH'
            set -e
            mkdir -p /dde
            cat > /dde/mkcert-rootCA.crt <<'CERT'
            %s
            CERT
            . /adapters/ca-trust.sh
            if type detect >/dev/null 2>&1 && detect; then
                configure
            fi
            echo "===BUNDLE==="
            cat "%s" 2>/dev/null || true
            SH, $caPem, $consumedBundle);

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $adaptersDir.':/adapters:ro',
            $image,
            'sh', '-c', $script,
        ]);
        $process->setTimeout(180);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("ca-trust run failed on %s:\nSTDOUT: %s\nSTDERR: %s", $image, $process->getOutput(), $process->getErrorOutput()),
        );

        $bundle = explode('===BUNDLE===', $process->getOutput(), 2)[1] ?? '';
        $this->assertStringContainsString(
            $marker,
            $bundle,
            sprintf('mkcert CA did not reach the consumed trust bundle %s on %s', $consumedBundle, $image),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function caTrustImageProvider(): iterable
    {
        // alpine:latest mirrors the whatwedo symfony base image's trust mechanism
        // (Alpine + ca-certificates), which was verified manually against
        // registry.whatwedo.ch/whatwedo/docker-base-images/symfony:v2.10.
        yield 'debian minimal (installs ca-certificates)' => ['debian:13', '/etc/ssl/certs/ca-certificates.crt'];
        yield 'alpine minimal (installs ca-certificates)' => ['alpine:latest', '/etc/ssl/certs/ca-certificates.crt'];
        yield 'opensuse leap (SUSE anchor dir)' => ['opensuse/leap', '/var/lib/ca-certificates/ca-bundle.pem'];
        yield 'fedora (update-ca-trust)' => ['fedora:latest', '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem'];
    }
}
