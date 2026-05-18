<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\TraefikLabelGenerator;
use PHPUnit\Framework\TestCase;

final class TraefikLabelGeneratorTest extends TestCase
{
    public function testGenerateLabelsRouterNameSanitizesDots(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['beispiel.test'], 'web');

        $this->assertStringContainsString('traefik.http.routers.beispiel-test-web.rule=', $labels[1]);
    }

    public function testGenerateLabelsRouterNameWithWorktreeHostname(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['beispiel-feature-x.test'], 'web');

        $this->assertStringContainsString('traefik.http.routers.beispiel-feature-x-test-web.rule=', $labels[1]);
    }

    public function testGenerateLabelsRouterNameWithDifferentServices(): void
    {
        $webLabels = TraefikLabelGenerator::generateLabels(['my-app.test'], 'web');
        $apiLabels = TraefikLabelGenerator::generateLabels(['my-app.test'], 'api');
        $workerLabels = TraefikLabelGenerator::generateLabels(['my-app.test'], 'worker');

        $this->assertStringContainsString('routers.my-app-test-web.rule=', $webLabels[1]);
        $this->assertStringContainsString('routers.my-app-test-api.rule=', $apiLabels[1]);
        $this->assertStringContainsString('routers.my-app-test-worker.rule=', $workerLabels[1]);
    }

    public function testGenerateLabelsSingleHostWithoutPort(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['beispiel.test'], 'web');

        $this->assertCount(4, $labels);
        $this->assertSame('traefik.enable=true', $labels[0]);
        $this->assertSame('traefik.http.routers.beispiel-test-web.rule=Host(`beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.rule=Host(`beispiel.test`)', $labels[2]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.tls=true', $labels[3]);
    }

    public function testGenerateLabelsRejectsHostnameWithBacktickInjection(): void
    {
        // Regression: a hostname containing a backtick would close the Traefik
        // `Host()` rule and let an attacker append `)||PathPrefix(`/admin` to
        // claim additional routes. Defense-in-depth — the worktree path is
        // sanitised upstream, but the VIRTUAL_HOST entry point is not.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/would not survive Traefik label interpolation/');

        TraefikLabelGenerator::generateLabels(['foo.test`)||PathPrefix(`/admin'], 'web');
    }

    public function testGenerateLabelsRejectsHostnameWithUnsafeWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/would not survive Traefik label interpolation/');

        TraefikLabelGenerator::generateLabels(['foo .test'], 'web');
    }

    public function testGenerateLabelsWithPort(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['beispiel.test'], 'web', 8080);

        $this->assertCount(5, $labels);
        $this->assertSame('traefik.http.routers.beispiel-test-web.rule=Host(`beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.services.beispiel-test-web.loadbalancer.server.port=8080', $labels[2]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.rule=Host(`beispiel.test`)', $labels[3]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.tls=true', $labels[4]);
    }

    public function testGenerateLabelsMultipleHostnames(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['beispiel.test', 'www.beispiel.test'], 'web');

        $this->assertCount(4, $labels);
        $this->assertSame('traefik.http.routers.beispiel-test-web.rule=Host(`beispiel.test`) || Host(`www.beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.rule=Host(`beispiel.test`) || Host(`www.beispiel.test`)', $labels[2]);
    }

    public function testGenerateLabelsMultipleHostnamesWithPort(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['app.test', 'www.app.test'], 'web', 3000);

        $this->assertCount(5, $labels);
        $this->assertSame('traefik.http.routers.app-test-web.rule=Host(`app.test`) || Host(`www.app.test`)', $labels[1]);
        $this->assertSame('traefik.http.services.app-test-web.loadbalancer.server.port=3000', $labels[2]);
        $this->assertSame('traefik.http.routers.app-test-web-tls.rule=Host(`app.test`) || Host(`www.app.test`)', $labels[3]);
    }

    public function testGenerateLabelsRouterNameUsesFirstHostname(): void
    {
        $labels = TraefikLabelGenerator::generateLabels(['primary.test', 'alias.test'], 'web');

        $this->assertStringContainsString('primary-test-web', $labels[1]);
    }

    public function testGenerateLabelsThrowsOnEmptyHostnames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one hostname is required');

        TraefikLabelGenerator::generateLabels([], 'web');
    }

    public function testGenerateLabelsRouterNameSanitizesDotsInServiceName(): void
    {
        // Compose lets a service name contain dots (`^[a-zA-Z0-9._-]+$`).
        // Embedded verbatim, the dot would be interpreted as a label-path
        // separator in `traefik.http.routers.<name>.rule`, producing an
        // ambiguous Traefik config key. The router name must be a flat
        // identifier — sanitise dots the same way we do for hostnames.
        $labels = TraefikLabelGenerator::generateLabels(['beispiel.test'], 'api.gateway');

        $this->assertSame('traefik.http.routers.beispiel-test-api-gateway.rule=Host(`beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.routers.beispiel-test-api-gateway-tls.rule=Host(`beispiel.test`)', $labels[2]);
        $this->assertSame('traefik.http.routers.beispiel-test-api-gateway-tls.tls=true', $labels[3]);
    }
}
