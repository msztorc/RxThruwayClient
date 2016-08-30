<?php

namespace Rx\Thruway;

use Rx\Thruway\Subject\SessionReplaySubject;
use Rx\Thruway\Subject\WebSocketSubject;
use Rx\Disposable\CompositeDisposable;
use Rx\Scheduler\EventLoopScheduler;
use React\EventLoop\LoopInterface;
use Rx\DisposableInterface;
use Thruway\Common\Utils;
use Rx\Subject\Subject;
use Rx\Observable;
use Rx\Thruway\Observable\{
    CallObservable, TopicObservable, RegisterObservable, WampChallengeException
};
use Thruway\Message\{
    AbortMessage, AuthenticateMessage, ChallengeMessage, Message, HelloMessage, PublishMessage, WelcomeMessage
};

final class Client
{
    private $url, $loop, $realm, $session, $options, $messages, $webSocket, $scheduler, $disposable;

    public function __construct(string $url, string $realm, array $options = [], LoopInterface $loop = null)
    {
        $this->url        = $url;
        $this->realm      = $realm;
        $this->options    = $options;
        $this->loop       = $loop ?? \EventLoop\getLoop();
        $this->scheduler  = new EventLoopScheduler($this->loop);
        $this->disposable = new CompositeDisposable();

        $open  = new Subject();
        $close = new Subject();

        $this->webSocket = new WebSocketSubject($url, ['wamp.2.json'], $open, $close);

        $this->messages = $this->webSocket
            ->map(function ($msg) {
                return $msg;
            })
            ->retryWhen([$this, '_reconnect'])
            ->map(function (Message $msg) {
                if ($msg instanceof AbortMessage) {
                    //@todo create an exception for this
                    throw new \Exception("Connection aborted because {$msg->getDetails()->message}");
                }
                return $msg;
            })
            ->share();

        $sessionSubject = new SessionReplaySubject(1);

        $open->map(function () {
            echo "Connected", PHP_EOL;
            $this->options['roles'] = $this->roles();
            return $helloMsg = new HelloMessage($this->realm, (object)$this->options);
        })->subscribe($this->webSocket, $this->scheduler);

        $close->subscribeCallback(function () use ($sessionSubject) {
            $sessionSubject->resetQueue();
            echo "Disconnected", PHP_EOL;
        });

        $this->session = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->multicast($sessionSubject)->refCount();

        $this->disposable->add($this->webSocket);
    }

    /**
     * @param string $uri
     * @param array $args
     * @param array $argskw
     * @param array $options
     * @return Observable
     */
    public function call(string $uri, array $args = [], array $argskw = [], array $options = null) :Observable
    {
        return $this->session
            ->take(1)
            ->flatMapTo(new CallObservable($uri, $this->messages, $this->webSocket, $args, $argskw, $options));
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return Observable
     */
    public function register(string $uri, callable $callback, array $options = []) :Observable
    {
        return $this->registerExtended($uri, $callback, $options, false);
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @param bool $extended
     * @return Observable
     */
    public function registerExtended(string $uri, callable $callback, array $options = [], bool $extended = true) :Observable
    {
        return $this->session
            ->flatMapTo(new RegisterObservable($uri, $callback, $this->messages, $this->webSocket, $options, $extended))
            ->subscribeOn($this->scheduler);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Observable
     */
    public function topic(string $uri, array $options = []) :Observable
    {
        return $this->session->flatMapTo(new TopicObservable($uri, $options, $this->messages, $this->webSocket))
            ->subscribeOn($this->scheduler);
    }

    /**
     * @param string $uri
     * @param mixed | Observable $obs
     * @param array $options
     * @return DisposableInterface
     */
    public function publish(string $uri, $obs, array $options = []) : DisposableInterface
    {
        $obs = $obs instanceof Observable ? $obs : Observable::just($obs);

        $completed = new Subject();

        $sub = $this->session
            ->takeUntil($completed)
            ->mapTo($obs->doOnCompleted(function () use ($completed) {
                $completed->onNext(0);
            }))
            ->lift(function () {
                return new SwitchFirstOperator();
            })
            ->map(function ($value) use ($uri, $options) {
                return new PublishMessage(Utils::getUniqueId(), (object)$options, $uri, [$value]);
            })
            ->subscribe($this->webSocket, $this->scheduler);

        $this->disposable->add($sub);

        return $sub;
    }

    public function onChallenge(callable $challengeCallback)
    {
        $sub = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof ChallengeMessage;
            })
            ->flatMap(function (ChallengeMessage $msg) use ($challengeCallback) {
                $challengeResult = null;
                try {
                    $challengeResult = call_user_func($challengeCallback, Observable::just([$msg->getAuthMethod(), $msg->getDetails()]));
                } catch (\Exception $e) {
                    throw new WampChallengeException($msg);
                }
                return $challengeResult->take(1);
            })
            ->map(function ($signature) {
                return new AuthenticateMessage($signature);
            })
            ->catchError(function (\Exception $ex) {
                if ($ex instanceof WampChallengeException) {
                    return Observable::just($ex->getErrorMessage());
                }
                return Observable::error($ex);
            })
            ->subscribe($this->webSocket, $this->scheduler);

        $this->disposable->add($sub);
    }

    public function close()
    {
        $this->disposable->dispose();
    }

    public function _reconnect(Observable $attempts)
    {
        $maxRetryDelay     = 300000;
        $initialRetryDelay = 1500;
        $retryDelayGrowth  = 1.5;
        $maxRetries        = 150;
        $exponent          = 0;

        return $attempts
            ->flatMap(function (\Exception $ex) use ($maxRetryDelay, $retryDelayGrowth, &$exponent, $initialRetryDelay) {
                $delay   = min($maxRetryDelay, pow($retryDelayGrowth, ++$exponent) + $initialRetryDelay);
                $seconds = number_format((float)$delay / 1000, 3, '.', '');;
                echo "Error: ", $ex->getMessage(), PHP_EOL, "Reconnecting in ${seconds} seconds...", PHP_EOL;
                return Observable::timer((int)$delay, $this->scheduler);
            })
            ->take($maxRetries);
    }

    private function roles()
    {
        return [
            "caller"     => [
                "features" => [
                    "caller_identification"    => true,
                    "progressive_call_results" => true
                ]
            ],
            "callee"     => [
                "features" => [
                    "caller_identification"      => true,
                    "pattern_based_registration" => true,
                    "shared_registration"        => true,
                    "progressive_call_results"   => true,
                    "registration_revocation"    => true
                ]
            ],
            "publisher"  => [
                "features" => [
                    "publisher_identification"      => true,
                    "subscriber_blackwhite_listing" => true,
                    "publisher_exclusion"           => true
                ]
            ],
            "subscriber" => [
                "features" => [
                    "publisher_identification"   => true,
                    "pattern_based_subscription" => true,
                    "subscription_revocation"    => true
                ]
            ]
        ];
    }
}
