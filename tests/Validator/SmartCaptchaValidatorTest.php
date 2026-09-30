<?php

namespace App\Tests\Validator;

use App\Validator\SmartCaptcha;
use App\Validator\SmartCaptchaValidator;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<SmartCaptchaValidator>
 */
final class SmartCaptchaValidatorTest extends ConstraintValidatorTestCase
{
    private MockHttpClient $yandex;
    private string $serverKey = 'server-key';

    protected function setUp(): void
    {
        $this->yandex = new MockHttpClient();
        parent::setUp();
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/api/v1/orders', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

        return new SmartCaptchaValidator($this->yandex, $requests, new NullLogger(), $this->serverKey);
    }

    public function testGenuineTokenPasses(): void
    {
        $sent = null;
        $this->yandex->setResponseFactory(static function (string $method, string $url, array $options) use (&$sent) {
            $sent = [$method, $url, $options['body']];

            return new JsonMockResponse(['status' => 'ok', 'message' => '', 'host' => 'sibir-outdoor.ru']);
        });

        $this->validator->validate('token-from-the-form', new SmartCaptcha());

        $this->assertNoViolation();
        self::assertSame('POST', $sent[0]);
        self::assertSame(SmartCaptchaValidator::VALIDATE_URL, $sent[1]);
        parse_str($sent[2], $body);
        self::assertSame(['secret' => 'server-key', 'token' => 'token-from-the-form', 'ip' => '203.0.113.7'], $body);
    }

    public function testRejectedOrMissingTokenIsAViolation(): void
    {
        $this->yandex->setResponseFactory([new JsonMockResponse(['status' => 'failed', 'message' => 'Token invalid or expired.'])]);
        $this->validator->validate('forged', new SmartCaptcha());
        $this->buildViolation('Подтвердите, что вы не робот')->assertRaised();
    }

    public function testMissingTokenIsAViolationWithoutAskingYandex(): void
    {
        $this->validator->validate(null, new SmartCaptcha());
        $this->buildViolation('Подтвердите, что вы не робот')->assertRaised();
        self::assertSame(0, $this->yandex->getRequestsCount());
    }

    public function testOutageOfYandexLetsTheFormThrough(): void
    {
        $this->yandex->setResponseFactory([
            new MockResponse('', ['http_code' => 500]),
            static fn () => throw new TransportException('timeout'),
        ]);

        $this->validator->validate('token', new SmartCaptcha());
        $this->validator->validate('token', new SmartCaptcha());

        $this->assertNoViolation();
    }

    public function testNothingIsCheckedWithoutAServerKey(): void
    {
        $this->serverKey = '';

        $this->createValidator()->validateInContext(null, new SmartCaptcha(), $this->context);

        $this->assertNoViolation();
        self::assertSame(0, $this->yandex->getRequestsCount());
    }
}
