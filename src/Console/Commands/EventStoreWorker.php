<?php

namespace DigitalRisks\LaravelEventStore\Console\Commands;

use DigitalRisks\LaravelEventStore\Contracts\CouldBeReceived;
use Illuminate\Console\Command;

use EventLoop\EventLoop;
use ReflectionClass;
use ReflectionProperty;
use Rxnet\EventStore\EventStore;
use Rxnet\EventStore\Data\EventRecord as EventData;
use Rxnet\EventStore\Record\AcknowledgeableEventRecord;
use Rxnet\EventStore\Record\EventRecord;
use Rxnet\EventStore\Record\JsonEventRecord;

class EventStoreWorker extends Command
{
    private $loop;

    private $timeout = 10;

    protected $signature = 'eventstore:worker --replay';

    protected $description = 'Worker handling incoming events from ES';

    protected $eventstore;

    public function __construct()
    {
        parent::__construct();

        $this->loop = EventLoop::getLoop();
    }

    public function handle()
    {
        $this->loop->stop();

        try {
            $this->processAllStreams();
            $this->loop->run();
        } catch (\Exception $e) {
            report($e);
        }

        $this->error("Lost connection with EventStore - reconnecting in $this->timeout");

        sleep($this->timeout);

        $this->handle();
    }

    public function processAllStreams()
    {
        $streams = config('eventstore.streams');

        foreach ($streams as $stream) {
            $eventStore = new EventStore();
            $connection = $eventStore->connect(config('eventstore.tcp_url'));

            $connection->subscribe(function () use ($eventStore, $stream) {
                $this->processStream($eventStore, $stream);
            }, 'report');
        }
    }

    private function processStream($eventStore, string $stream)
    {
        $eventStore
            ->persistentSubscription($stream, config('eventstore.group'))
            ->subscribe(function (AcknowledgeableEventRecord $event) {
                $url = config('eventstore.http_url')."/streams/{$event->getStreamId()}/{$event->getNumber()}";
                $this->info($url);

                try {
                    $this->dispatch($event);
                    $event->ack();
                } catch (\Exception $e) {
                    dump([
                        'id' => $event->getId(),
                        'number' => $event->getNumber(),
                        'stream' => $event->getStreamId(),
                        'type' => $event->getType(),
                        'created' => $event->getCreated(),
                        'data' => $event->getData(),
                        'metadata' => $event->getMetadata(),
                    ]);

                    $event->nack();

                    report($e);
                }
            }, 'report');
    }

    public function dispatch(EventRecord $eventRecord)
    {
        $event = $this->makeSerializableEvent($eventRecord);

        if ($localEvent = $this->mapToLocalEvent($event)) {
            event($localEvent);
        }
        else {
            event($event->getType(), $event);
        }
    }

    protected function makeSerializableEvent(EventRecord $event)
    {
        $data = new EventData();

        $data->setEventType($event->getType());
        $data->setCreatedEpoch($event->getCreated()->getTimestamp() * 1000);
        $data->setData(json_encode($event->getData()));
        $data->setMetadata(json_encode($event->getMetadata()));

        return new JsonEventRecord($data);
    }

    protected function mapToLocalEvent($event)
    {
        $eventToClass = config('eventstore.event_to_class');
        $className = $eventToClass ? $eventToClass($event) : 'App\Events\\' . $event->getType();

        if (! class_exists($className)) return;

        $reflection = new ReflectionClass($className);

        if (! $reflection->implementsInterface(CouldBeReceived::class)) return;

        $localEvent = new $className();
        $props = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $data = $event->getData();

        foreach ($props as $prop) {
            $key = $prop->getName();
            $localEvent->$key = $data[$key] ?? null;
        }

        $localEvent->setEventRecord($event);

        return $localEvent;
    }
}
