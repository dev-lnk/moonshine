<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use MoonShine\Applies\Filters\JsonModelApply;
use MoonShine\Fields\Json;
use MoonShine\Fields\Text;
use MoonShine\Handlers\ExportHandler;
use MoonShine\Handlers\ImportHandler;
use MoonShine\Tests\Fixtures\Models\Item;
use MoonShine\Tests\Fixtures\Resources\TestCommentResource;
use MoonShine\Tests\Fixtures\Resources\TestItemResource;
use MoonShine\Tests\Fixtures\Resources\TestResource;
use MoonShine\Tests\Fixtures\Resources\TestResourceBuilder;

use function Pest\Laravel\get;

uses()->group('fields');
uses()->group('json-field');

beforeEach(function () {
    $this->item = createItem(countComments: 0);

    expect($this->item->data)
        ->toBeEmpty();
});

function createResourceWithJson(Json $json)
{
    return TestResourceBuilder::new(Item::class)
        ->setTestFields([
            ...(new TestItemResource())->fields(),
            $json,
        ]);
}

function testJsonValue(TestResource $resource, Item $item, array $data, ?array $expectedData = null)
{
    asAdmin()->put(
        $resource->route('crud.update', $item->getKey()),
        [
            'data' => $data,
        ]
    )->assertRedirect();

    $item->refresh();

    expect($item->data->toArray())->toBe($expectedData ?? $data);
}

it('apply as base', function () {
    $resource = createResourceWithJson(
        Json::make('Data')->fields([
            Text::make('Title'),
            Text::make('Value'),
        ])
    );

    $data = [
        ['title' => 'Title 1', 'value' => 'Value 1'],
        ['title' => 'Title 2', 'value' => 'Value 2'],
    ];

    testJsonValue($resource, $this->item, $data);
});

it('apply as base with default', function () {
    $data = [
        ['title' => 'Title 1', 'value' => 'Value 1'],
        ['title' => 'Title 2', 'value' => 'Value 2'],
    ];

    $resource = createResourceWithJson(
        Json::make('Data')->fields([
            Text::make('Title'),
            Text::make('Value'),
        ])->default($data)
    );

    asAdmin()->put(
        $resource->route('crud.update', $this->item->getKey())
    )->assertRedirect();

    $this->item->refresh();

    expect($this->item->data->toArray())->toBe($data);
});

it('apply as key value', function () {
    $resource = createResourceWithJson(
        Json::make('Data')->keyValue()
    );

    $data = [
        ['key' => 'Title 1', 'value' => 'Value 1'],
        ['key' => 'Title 2', 'value' => 'Value 2'],
    ];

    testJsonValue($resource, $this->item, $data, ['Title 1' => 'Value 1', 'Title 2' => 'Value 2']);
});

it('apply as only value', function () {
    $resource = createResourceWithJson(
        Json::make('Data')->onlyValue()
    );

    $data = [
        ['value' => 'Value 1'],
        ['value' => 'Value 2'],
    ];

    testJsonValue($resource, $this->item, $data, ['Value 1', 'Value 2']);
});

it('apply as relation', function () {
    $resource = createResourceWithJson(
        Json::make('Comments')->asRelation(new TestCommentResource())
    );

    $data = [
        ['id' => '', 'content' => 'Test', 'user_id' => 1],
    ];

    asAdmin()->put(
        $resource->route('crud.update', $this->item->getKey()),
        [
            'comments' => $data,
        ]
    )->assertRedirect();

    $this->item->refresh();

    expect($this->item->comments->first())
        ->content
        ->toBe('Test');

    $data = [
        ['id' => $this->item->comments->first()->getKey(), 'content' => 'Test 2', 'user_id' => 1],
    ];

    asAdmin()->put(
        $resource->route('crud.update', $this->item->getKey()),
        [
            'comments' => $data,
        ]
    )->assertRedirect();

    $this->item->refresh();

    expect($this->item->comments->first())
        ->content
        ->toBe('Test 2');
});

it('apply as filter', function (): void {
    $field = Json::make('Json')
        ->fields(exampleFields()->toArray())
        ->wrapName('filters');

    $query = Item::query();

    get('/?filters[json][0][title]=test');

    $field
        ->onApply((new JsonModelApply())->apply($field))
        ->apply(
            static fn (Builder $query) => $query,
            $query
        );

    expect($query->toRawSql())
        ->toContain('json_contains');
});

function jsonExport(Item $item): ?string
{
    $data = [
        ['title' => 'Title 1', 'value' => 'Value 1'],
        ['title' => 'Title 2', 'value' => 'Value 2'],
    ];

    $item->data = $data;
    $item->save();

    $resource = createResourceWithJson(
        Json::make('Data')->fields([
            Text::make('Title'),
            Text::make('Value'),
        ])->showOnExport()
    );

    $export = ExportHandler::make('');

    asAdmin()->get(
        $resource->route('handler', query: ['handlerUri' => $export->uriKey()])
    )->assertDownload();

    $file = Storage::disk('public')->get('test-resource.csv');

    expect($file)
        ->toContain('Title 1', 'Title 2', 'Value 1', 'Value 2');

    return $file;
}
it('export', function (): void {
    jsonExport($this->item);
});

it('import', function (): void {
    $data = [
        ['title' => 'Title 1', 'value' => 'Value 1'],
        ['title' => 'Title 2', 'value' => 'Value 2'],
    ];

    $file = jsonExport($this->item);

    $resource = createResourceWithJson(
        Json::make('Data')->fields([
            Text::make('Title'),
            Text::make('Value'),
        ])->useOnImport()
    );

    $import = ImportHandler::make('');

    asAdmin()->post(
        $resource->route('handler', query: ['handlerUri' => $import->uriKey()]),
        [$import->getInputName() => $file]
    )->assertRedirect();

    $this->item->refresh();

    expect($this->item->data->toArray())
        ->toBe($data);

});
