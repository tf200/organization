<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Middleware;

use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Middleware\SubscriptionMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A stand-in controller carrying both shapes of route we care about.
 */
class PublicProbeController extends Controller {
	#[PublicPage]
	public function openRoute(): void {
	}

	public function guardedRoute(): void {
	}
}

/**
 * Covers public-route detection.
 *
 * Nextcloud 34 removed ControllerMethodReflector::hasAnnotationOrAttribute();
 * the class now exposes only hasAnnotation() and getAnnotationParameter().
 * Because this middleware is registered for the app's own container, the
 * resulting fatal turned every route into a 500 — including the app's own
 * page — so the app was completely unreachable.
 */
class SubscriptionMiddlewareTest extends TestCase {
	private IRequest $request;
	private SubscriptionMiddleware $middleware;
	private PublicProbeController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->middleware = new SubscriptionMiddleware(
			$this->request,
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SubscriptionMapper::class),
			$this->createMock(OrganizationMapper::class),
		);
		$this->controller = new PublicProbeController('organization', $this->request);
	}

	private function isPublicRoute(string $methodName): bool {
		$method = new \ReflectionMethod(SubscriptionMiddleware::class, 'isPublicRoute');
		$method->setAccessible(true);
		return $method->invoke($this->middleware, $this->controller, $methodName);
	}

	public function testMethodMarkedPublicPageIsPublic(): void {
		$this->assertTrue($this->isPublicRoute('openRoute'));
	}

	public function testMethodWithoutPublicPageIsNotPublic(): void {
		$this->request->method('getPathInfo')->willReturn('/apps/organization/organizations');
		$this->assertFalse($this->isPublicRoute('guardedRoute'));
	}

	public function testLogoutPathIsPublic(): void {
		$this->request->method('getPathInfo')->willReturn('/logout');
		$this->assertTrue($this->isPublicRoute('guardedRoute'));
	}

	/**
	 * A route that does not exist on the controller must not escalate a 404
	 * into a 500. Reflection throws here, and the guard has to absorb it.
	 */
	public function testUnknownMethodDoesNotThrow(): void {
		$this->request->method('getPathInfo')->willReturn('/apps/organization/nope');
		$this->assertFalse($this->isPublicRoute('methodThatDoesNotExist'));
	}
}
