<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Webong\Cogent\Documentation\AsyncApiExporter;

it('describes resource broadcast channels, event names and payloads', function (): void {
    $document = (new AsyncApiExporter)->export(
        [dirname(__DIR__).'/Fixtures/Events'],
        [dirname(__DIR__).'/Fixtures/Models'],
    );

    $document = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($document['asyncapi'])->toBe('3.0.0')
        ->and($document['channels']['PostUpdatedChannel']['address'])->toBe('private-posts.{parameter}')
        ->and($document['channels']['PostUpdatedChannel']['x-cogent'])->toBe(['type' => 'private', 'dynamic' => true])
        ->and($document['operations']['PostUpdated']['action'])->toBe('send')
        ->and($document['components']['messages']['PostUpdated']['name'])->toBe('post.updated')
        ->and($document['components']['schemas']['PostUpdatedPayload']['properties']['post']['$ref'])
        ->toBe('#/components/schemas/PostResource')
        ->and($document['components']['schemas']['PostUpdatedPayload']['properties']['reason'])->toBe(['type' => 'string'])
        ->and($document['components']['schemas']['PostResource']['properties']['title']['type'])->toBe('string')
        ->and($document['components']['messages'])->toHaveKeys(['BroadcastingPostCreated', 'BroadcastingPostUpdated', 'BroadcastingPostDeleted'])
        ->and($document['components']['messages']['BroadcastingPostUpdated']['payload']['$ref'])->toBe('#/components/schemas/PostResource');
});

it('prints AsyncAPI from artisan', function (): void {
    Artisan::call('cogent:plans', [
        '--format' => 'asyncapi',
        '--events-path' => [dirname(__DIR__).'/Fixtures/Events'],
        '--models-path' => [dirname(__DIR__).'/Fixtures/Models'],
        '--title' => 'Application websockets',
    ]);

    $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($document['info']['title'])->toBe('Application websockets')
        ->and($document['channels'])->toHaveKey('PostUpdatedChannel');
});
