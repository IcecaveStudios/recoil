<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\React;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use Recoil\Exception\TerminatedException;
use Recoil\Kernel\Api;
use Recoil\Kernel\Exception\KernelStoppedException;
use Recoil\Kernel\Exception\StrandException;
use Recoil\Kernel\Kernel;
use Recoil\Kernel\Strand;
use Recoil\Kernel\StrandObserver;
use RuntimeException;
use Throwable;

/**
 * A Recoil coroutine kernel based on a ReactPHP event loop.
 */
final class ReactKernel implements Kernel
{
    /**
     * Execute a coroutine on a new kernel.
     *
     * This is a convenience method for:
     *
     *     $kernel = new Kernel($eventLoop);
     *     $kernel->waitFor($coroutine);
     *
     * @param mixed              $coroutine The strand's entry-point.
     * @param LoopInterface|null $eventLoop The event loop to use (null = default).
     *
     * @return mixed               The return value of the coroutine.
     * @throws Throwable           The exception produced by the coroutine, if any.
     * @throws TerminatedException The strand has been terminated.
     * @throws StrandException A strand or strand observer has failure was not handled by the exception handler.
     */
    public static function start($coroutine, LoopInterface $eventLoop = null)
    {
        $kernel = new self($eventLoop);
        $strand = $kernel->execute($coroutine);

        return $kernel->waitForStrand($strand);
    }

    /**
     * @param LoopInterface|null $eventLoop The event loop.
     * @param Api|null           $api       The kernel API.
     */
    public function __construct(LoopInterface $eventLoop = null, Api $api = null)
    {
        $this->eventLoop = $eventLoop ?: Factory::create();
        $this->api = $api ?: new ReactApi($this->eventLoop);
    }

    /**
     * Execute a coroutine on a new strand.
     *
     * Execution is deferred until control returns to the kernel. This allows
     * the caller to manipulate the returned {@see Strand} object before
     * execution begins.
     *
     * @param mixed $coroutine The strand's entry-point.
     */
    public function execute($coroutine) : Strand
    {
        $strand = new ReactStrand($this->nextId++, $this, $this->api);

        $this->eventLoop->futureTick(
            function () use ($strand, $coroutine) {
                $strand->start($coroutine);
            }
        );

        return $strand;
    }

    /**
     * Run the kernel until all strands exit or the kernel is stopped.
     *
     * Calls to wait(), {@see Kernel::waitForStrand()} and {@see Kernel::waitFor()}
     * may be nested. This can be useful within synchronous code to block
     * execution until a particular asynchronous operation is complete. Care
     * must be taken to avoid deadlocks.
     *
     * @return bool            False if the kernel was stopped with {@see Kernel::stop()}; otherwise, true.
     * @throws StrandException A strand or strand observer has failure was not handled by the exception handler.
     */
    public function wait() : bool
    {
        if ($this->fatalException) {
            throw $this->fatalException;
        }

        $this->isRunning = true;
        $this->eventLoop->run();

        if ($this->fatalException) {
            throw $this->fatalException;
        }

        return $this->isRunning;
    }

    /**
     * Run the kernel until a specific strand exits or the kernel is stopped.
     *
     * Calls to {@see Kernel::wait()}, waitForStrand() and {@see Kernel::waitFor()}
     * may be nested. This can be useful within synchronous code to block
     * execution until a particular asynchronous operation is complete. Care
     * must be taken to avoid deadlocks.
     *
     * @param Strand $strand The strand to wait for.
     *
     * @return mixed                  The strand result, on success.
     * @throws Throwable              The exception thrown by the strand, if failed.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException Execution was stopped with {@see Kernel::stop()}.
     * @throws StrandException        A strand or strand observer has failure was not handled by the exception handler.
     */
    public function waitForStrand(Strand $strand)
    {
        if ($this->fatalException) {
            throw $this->fatalException;
        }

        $observer = new class implements StrandObserver
        {
            public $eventLoop;
            public $pending = true;
            public $value;
            public $exception;

            public function success(Strand $strand, $value)
            {
                $this->pending = false;
                $this->value = $value;
                $this->eventLoop->stop();
            }

            public function failure(Strand $strand, Throwable $exception)
            {
                $this->pending = false;
                $this->exception = $exception;
                $this->eventLoop->stop();
            }

            public function terminated(Strand $strand)
            {
                $this->pending = false;
                $this->exception = new TerminatedException($strand);
                $this->eventLoop->stop();
            }
        };

        $observer->eventLoop = $this->eventLoop;
        $strand->setObserver($observer);

        $this->isRunning = true;

        do {
            $this->eventLoop->run();

            if ($this->fatalException) {
                throw $this->fatalException;
            } elseif (!$this->isRunning) {
                throw new KernelStoppedException();
            }
        } while ($observer->pending);

        if ($observer->exception) {
            throw $observer->exception;
        }

        return $observer->value;
    }

    /**
     * Run the kernel until the given coroutine returns or the kernel is stopped.
     *
     * This is a convenience method equivalent to:
     *
     *      $strand = $kernel->execute($coroutine);
     *      $kernel->waitForStrand($strand);
     *
     * Calls to {@see Kernel::wait()}, {@see Kernel::waitForStrand()} and waitFor()
     * may be nested. This can be useful within synchronous code to block
     * execution until a particular asynchronous operation is complete. Care
     * must be taken to avoid deadlocks.
     *
     * @param mixed $coroutine The coroutine to execute.
     *
     * @return mixed                  The return value of the coroutine.
     * @throws Throwable              The exception produced by the coroutine, if any.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException Execution was stopped with {@see Kernel::stop()}.
     * @throws StrandException        A strand or strand observer has failure was not handled by the exception handler.
     */
    public function waitFor($coroutine)
    {
        return $this->waitForStrand(
            $this->execute($coroutine)
        );
    }

    /**
     * Stop the kernel.
     *
     * All nested calls to {@see Kernel::wait()}, {@see Kernel::waitForStrand()}
     * or {@see Kernel::waitFor()} are stopped.
     *
     * wait() returns false when the kernel is stopped, the other variants throw
     * a {@see KernelStoppedException}.
     */
    public function stop()
    {
        $this->isRunning = false;
        $this->eventLoop->stop();
    }

    /**
     * Set the exception handler.
     *
     * The exception handler is invoked whenever an exception propagates to the
     * top of a strand's call-stack, or when a strand observer throws an
     * exception.
     *
     * The exception handler function must accept a single parameter of type
     * {@see StrandException} and return a boolean indicating whether or not the
     * exception was handled.
     *
     * If the exception handler returns false, or is not set (the default), the
     * exception will be thrown by the outer-most call to {@see Kernel::wait()},
     * {@see Kernel::waitForStrand()} or {@see Kernel::waitFor()}, after which
     * the kernel may not be restarted.
     *
     * @param callable|null $fn The exception handler (null = remove).
     */
    public function setExceptionHandler(callable $fn = null)
    {
        $this->exceptionHandler = $fn;
    }

    /**
     * Notify the kernel of a strand or strand observer failure.
     *
     * @access private
     *
     * This method is used by the strand implementation and should not be called
     * by the user.
     */
    public function triggerException(StrandException $exception)
    {
        assert(
            $this->fatalException === null,
            'an exception has already been triggered'
        );

        if (
            $this->exceptionHandler &&
            ($this->exceptionHandler)($exception)
        ) {
            return;
        }

        $this->fatalException = $exception;
        $this->eventLoop->stop();
    }

    /**
     * @var LoopInterface The event loop.
     */
    private $eventLoop;

    /**
     * @var Api The kernel API.
     */
    private $api;

    /**
     * @var int The next strand ID.
     */
    private $nextId = 1;

    /**
     * @var bool Set to false when stop() is called.
     */
    private $isRunning = false;

    /**
     * @var callable|null The exception handler.
     */
    private $exceptionHandler;

    /**
     * @var StrandException|null The exception passed to triggerException(), if it has not been handled.
     */
    private $fatalException;
}
