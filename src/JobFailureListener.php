<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;

/**
 * The queue-backed half of the integration, and the "queue-backed" note the PHP core's README promised.
 *
 * A job that exhausted its attempts is a fault a developer has to see, and it is the one fault that never
 * passes through a request: there is no response to terminate after and no request context to attach. The
 * `queue` tag is what tells the two apart in the cockpit.
 *
 * A job that merely FAILED an attempt and was released for a retry fires no JobFailed. That is
 * deliberate: reporting every attempt of a job that succeeds on the third one is noise, and Laravel's own
 * exception handler still reports the attempt if the application wants it.
 */
final class JobFailureListener
{
    public function __construct(private readonly Container $container)
    {
    }

    /** Total, by contract: a listener that throws would take the worker's failure path down with it. */
    public function handle(JobFailed $event): void
    {
        try {
            if (!Reported::firstTime($event->exception)) {
                return;
            }

            $tags = ['queue' => $this->queueName($event)];
            $name = $event->job->resolveName();
            if ($name !== '') {
                $tags['job'] = $name;
            }
            if ($event->connectionName !== '') {
                $tags['connection'] = $event->connectionName;
            }

            $this->container->make(Client::class)->captureException($event->exception, ['tags' => $tags]);
        } catch (\Throwable) {
            // Deliberately empty. FR-2 b: nothing this package does may reach the application.
        }
    }

    private function queueName(JobFailed $event): string
    {
        $queue = $event->job->getQueue();

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }
}
