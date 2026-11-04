<?php

declare(strict_types=1);

namespace Webong\Cogent\Documentation;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Webong\Cogent\Attributes\WithResource;
use Webong\Cogent\Concerns\BroadcastsAsResource;
use Webong\Cogent\Concerns\BroadcastsResources;
use Webong\Cogent\Concerns\SnapshotsBroadcasts;
use Webong\Cogent\Resources\ResourcePlan;

/** Static AsyncAPI description of Cogent's resource-backed broadcasts. */
final class AsyncApiExporter
{
    /** @param list<string> $eventPaths @param list<string> $modelPaths */
    public function export(array $eventPaths, array $modelPaths, string $title = 'Cogent broadcasts'): array
    {
        $discovery = new ClassDiscovery;
        $events = $discovery->in($eventPaths, static fn (string $class): bool => is_subclass_of($class, ShouldBroadcast::class)
            && (in_array(BroadcastsResources::class, class_uses_recursive($class), true)
                || in_array(SnapshotsBroadcasts::class, class_uses_recursive($class), true)));
        $models = $discovery->in($modelPaths, static fn (string $class): bool => is_subclass_of($class, Model::class)
            && in_array(BroadcastsAsResource::class, class_uses_recursive($class), true));
        $messages = $operations = $channels = [];

        foreach ($events as $event) {
            $messages[class_basename($event)] = ['name' => $this->eventName($event),
                'payload' => ['$ref' => '#/components/schemas/'.class_basename($event).'Payload']];
            $operations[class_basename($event)] = $this->operation($event, $this->channels($event));
        }

        foreach ($models as $model) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $name = class_basename($model).ucfirst($event);
                $messages[$name] = ['name' => $name, 'payload' => $this->modelPayload($model)];
                $operations[$name] = $this->operation($model, $this->channels($model, $event));
            }
        }

        foreach ($operations as $name => $operation) {
            $operations[$name]['messages'] = [['$ref' => '#/channels/'.$operation['x-channel'].'/messages/'.$name]];
            $channels[$operation['x-channel']] ??= ['address' => $operation['x-address'], 'messages' => [],
                'x-cogent' => ['type' => $operation['x-type'], 'dynamic' => $operation['x-dynamic']]];
            $channels[$operation['x-channel']]['messages'][$name] = ['$ref' => '#/components/messages/'.$name];
            unset($operations[$name]['x-channel'], $operations[$name]['x-address'], $operations[$name]['x-type'], $operations[$name]['x-dynamic']);
        }

        $resources = array_filter(array_map(ResourcePlan::guessResource(...), $models));

        ksort($channels);
        ksort($operations);

        return ['asyncapi' => '3.0.0', 'info' => ['title' => $title, 'version' => '1.0.0'],
            'channels' => (object) $channels, 'operations' => (object) $operations,
            'components' => ['messages' => (object) $messages, 'schemas' => (object) ($this->schemas($events)
                + (new ResourceSchemaInferer)->infer(array_values(array_unique($resources)))['schemas'])]];
    }

    private function operation(string $class, array $channel): array
    {
        return ['action' => 'send', 'channel' => ['$ref' => '#/channels/'.$channel['key']],
            'x-channel' => $channel['key'], 'x-address' => $channel['address'],
            'x-type' => $channel['type'], 'x-dynamic' => $channel['dynamic']];
    }

    /** @param class-string<Model> $model */
    private function modelPayload(string $model): array
    {
        $resource = ResourcePlan::guessResource($model);

        return $resource === null ? ['type' => 'object'] : ['$ref' => '#/components/schemas/'.class_basename($resource)];
    }

    private function channels(string $class, ?string $event = null): array
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod('broadcastOn')) {
            return $this->unknownChannel($class, $event);
        }

        $method = $reflection->getMethod('broadcastOn');
        $file = $method->getFileName();

        if ($file === false) {
            return $this->unknownChannel($class, $event);
        }

        $parser = (new ParserFactory)->createForHostVersion();
        $nodes = (new NodeFinder)->findInstanceOf($parser->parse((string) file_get_contents($file)), Node\Stmt\ClassMethod::class);

        foreach ($nodes as $node) {
            if ($node->name->toString() === 'broadcastOn' && $node->getStartLine() === $method->getStartLine()) {
                $new = (new NodeFinder)->findFirstInstanceOf($node, Expr\New_::class);

                if ($new !== null) {
                    return $this->channel($new, $class, $event);
                }
            }
        }

        return $this->unknownChannel($class, $event);
    }

    private function unknownChannel(string $class, ?string $event): array
    {
        return ['key' => class_basename($class).($event === null ? '' : ucfirst($event)).'Channel',
            'address' => '', 'type' => 'public', 'dynamic' => true];
    }

    private function channel(Expr\New_ $new, string $class, ?string $event): array
    {
        $type = match ($new->class instanceof Node\Name ? $new->class->getLast() : '') {
            class_basename(PrivateChannel::class) => 'private',
            class_basename(PresenceChannel::class) => 'presence',
            default => 'public',
        };
        $argument = $new->args[0]->value ?? null;
        $parts = $argument instanceof Expr\BinaryOp\Concat ? $this->concat($argument) : [$argument];
        $address = '';
        $dynamic = false;

        foreach ($parts as $part) {
            if ($part instanceof String_) {
                $address .= $part->value;
            } else {
                $address .= '{parameter}';
                $dynamic = true;
            }
        }

        $address = ($type === 'public' ? '' : $type.'-').$address;
        $key = class_basename($class).($event === null ? '' : ucfirst($event)).'Channel';

        return ['key' => $key, 'address' => $address, 'type' => $type, 'dynamic' => $dynamic];
    }

    /** @return list<Node|null> */
    private function concat(Expr $expression): array
    {
        if (! $expression instanceof Expr\BinaryOp\Concat) {
            return [$expression];
        }

        return [...$this->concat($expression->left), ...$this->concat($expression->right)];
    }

    private function eventName(string $event): string
    {
        $reflection = new ReflectionClass($event);

        if (! $reflection->hasMethod('broadcastAs')) {
            return class_basename($event);
        }

        $file = $reflection->getMethod('broadcastAs')->getFileName();

        if ($file !== false) {
            $parser = (new ParserFactory)->createForHostVersion();
            $nodes = (new NodeFinder)->findInstanceOf($parser->parse((string) file_get_contents($file)), Node\Stmt\ClassMethod::class);

            foreach ($nodes as $node) {
                if ($node->name->toString() !== 'broadcastAs' || $node->getStartLine() !== $reflection->getMethod('broadcastAs')->getStartLine()) {
                    continue;
                }

                $return = (new NodeFinder)->findFirstInstanceOf($node, Node\Stmt\Return_::class);

                if ($return?->expr instanceof String_) {
                    return $return->expr->value;
                }
            }
        }

        return class_basename($event);
    }

    /** @param list<class-string> $events */
    private function schemas(array $events): array
    {
        $resources = $payloads = [];

        foreach ($events as $event) {
            $properties = $required = [];

            foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic() || $property->getName() === 'broadcastQueue') {
                    continue;
                }

                $properties[$property->getName()] = $this->propertySchema($property, $resources);
                $required[] = $property->getName();
            }

            $payloads[class_basename($event).'Payload'] = ['type' => 'object', 'properties' => (object) $properties, 'required' => $required];
        }

        return $payloads + (new ResourceSchemaInferer)->infer(array_values(array_unique($resources)))['schemas'];
    }

    /** @param list<class-string> $resources */
    private function propertySchema(ReflectionProperty $property, array &$resources): array|\stdClass
    {
        $attribute = $property->getAttributes(WithResource::class)[0] ?? null;
        $type = $property->getType();

        if ($attribute !== null) {
            $resources[] = $attribute->newInstance()->class;

            return ['$ref' => '#/components/schemas/'.class_basename($attribute->newInstance()->class)];
        }

        if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
            $resource = ResourcePlan::guessResource($type->getName());

            if ($resource !== null) {
                $resources[] = $resource;

                return ['$ref' => '#/components/schemas/'.class_basename($resource)];
            }
        }

        return match ($type instanceof ReflectionNamedType ? $type->getName() : null) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            default => new \stdClass,
        };
    }
}
