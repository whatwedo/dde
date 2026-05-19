<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\TraefikLabelGenerator;
use PHPUnit\Framework\TestCase;

final class TraefikLabelGeneratorTest extends TestCase
{
    public function testGenerateRouterNameSanitizesDots(): void
    {
        $this->assertSame('beispiel-test-web', TraefikLabelGenerator::generateRouterName('beispiel.test', 'web'));
    }

    public function testGenerateRouterNameWithWorktreeHostname(): void
    {
        $this->assertSame(
            'beispiel-feature-x-test-web',
            TraefikLabelGenerator::generateRouterName('beispiel-feature-x.test', 'web'),
        );
    }

    public function testGenerateRouterNameWithDifferentServices(): void
    {
        $this->assertSame('my-app-test-web', TraefikLabelGenerator::generateRouterName('my-app.test', 'web'));
        $this->assertSame('my-app-test-api', TraefikLabelGenerator::generateRouterName('my-app.test', 'api'));
        $this->assertSame('my-app-test-worker', TraefikLabelGenerator::generateRouterName('my-app.test', 'worker'));
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
}
