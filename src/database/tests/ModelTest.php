<?php

namespace HyperfTest\Database;

use DateTime;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Model\Register;
use HyperfTest\Database\Stubs\DateModelStub;
use HyperfTest\Database\Stubs\ModelCamelStub;
use HyperfTest\Database\Stubs\ModelCastingStub;
use HyperfTest\Database\Stubs\ModelDestroyStub;
use HyperfTest\Database\Stubs\ModelDynamicVisibleStub;
use HyperfTest\Database\Stubs\ModelFindWithWritePdoStub;
use HyperfTest\Database\Stubs\ModelSaveStub;
use HyperfTest\Database\Stubs\ModelStub;
use HyperfTest\Database\Stubs\ModelWithoutRelationStub;
use HyperfTest\Database\Stubs\ModelWithoutTableStub;
use HyperfTest\Database\Stubs\ModelWithStub;
use Mockery;
use stdClass;
use Exception;
use ReflectionClass;
use DateTimeImmutable;
use DateTimeInterface;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Hyperf\Database\ConnectionInterface as Connection;
use Hyperf\Database\Model\Model;
use Foo\Bar\ModelNamespacedStub;
use Hyperf\Database\Model\Builder;
use Hyperf\Utils\InteractsWithTime;
use Psr\EventDispatcher\EventDispatcherInterface as Dispatcher;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Query\Grammars\Grammar;
use Hyperf\Database\Query\Processors\Processor;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Relations\Relation;
use Hyperf\Utils\Collection as BaseCollection;
use Hyperf\Database\Model\Relations\BelongsTo;

class ModelTest extends TestCase
{
    use InteractsWithTime;

    public function setUp()
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now());
    }

    public function tearDown()
    {
        parent::tearDown();

        Mockery::close();
        Carbon::setTestNow(null);

        Register::unsetEventDispatcher();
        Carbon::resetToStringFormat();
    }

    public function testAttributeManipulation()
    {
        $model = new ModelStub();
        $model->name = 'foo';
        $this->assertEquals('foo', $model->name);
        $this->assertTrue(isset($model->name));
        unset($model->name);
        $this->assertFalse(isset($model->name));

        // test mutation
        $model->list_items = ['name' => 'taylor'];
        $this->assertEquals(['name' => 'taylor'], $model->list_items);
        $attributes = $model->getAttributes();
        $this->assertEquals(json_encode(['name' => 'taylor']), $attributes['list_items']);
    }

    public function testDirtyAttributes()
    {
        $model = new ModelStub(['foo' => '1', 'bar' => 2, 'baz' => 3]);
        $model->syncOriginal();
        $model->foo = 1;
        $model->bar = 20;
        $model->baz = 30;

        $this->assertTrue($model->isDirty());
        $this->assertFalse($model->isDirty('foo'));
        $this->assertTrue($model->isDirty('bar'));
        $this->assertTrue($model->isDirty('foo', 'bar'));
        $this->assertTrue($model->isDirty(['foo', 'bar']));
    }

    public function testDirtyOnCastOrDateAttributes()
    {
        $model = new ModelCastingStub();
        $model->setDateFormat('Y-m-d H:i:s');
        $model->boolAttribute = 1;
        $model->foo = 1;
        $model->bar = '2017-03-18';
        $model->dateAttribute = '2017-03-18';
        $model->datetimeAttribute = '2017-03-23 22:17:00';
        $model->syncOriginal();

        $model->boolAttribute = true;
        $model->foo = true;
        $model->bar = '2017-03-18 00:00:00';
        $model->dateAttribute = '2017-03-18 00:00:00';
        $model->datetimeAttribute = null;

        $this->assertTrue($model->isDirty());
        $this->assertTrue($model->isDirty('foo'));
        $this->assertTrue($model->isDirty('bar'));
        $this->assertFalse($model->isDirty('boolAttribute'));
        $this->assertFalse($model->isDirty('dateAttribute'));
        $this->assertTrue($model->isDirty('datetimeAttribute'));
    }

    public function testCleanAttributes()
    {
        $model = new ModelStub(['foo' => '1', 'bar' => 2, 'baz' => 3]);
        $model->syncOriginal();
        $model->foo = 1;
        $model->bar = 20;
        $model->baz = 30;

        $this->assertFalse($model->isClean());
        $this->assertTrue($model->isClean('foo'));
        $this->assertFalse($model->isClean('bar'));
        $this->assertFalse($model->isClean('foo', 'bar'));
        $this->assertFalse($model->isClean(['foo', 'bar']));
    }

    public function testCalculatedAttributes()
    {
        $model = new ModelStub;
        $model->password = 'secret';
        $attributes = $model->getAttributes();

        // ensure password attribute was not set to null
        $this->assertArrayNotHasKey('password', $attributes);
        $this->assertEquals('******', $model->password);

        $hash = 'e5e9fa1ba31ecd1ae84f75caaa474f3a663f05f4';

        $this->assertEquals($hash, $attributes['password_hash']);
        $this->assertEquals($hash, $model->password_hash);
    }

    public function testArrayAccessToAttributes()
    {
        $model = new ModelStub(['attributes' => 1, 'connection' => 2, 'table' => 3]);
        unset($model['table']);

        $this->assertTrue(isset($model['attributes']));
        $this->assertEquals($model['attributes'], 1);
        $this->assertTrue(isset($model['connection']));
        $this->assertEquals($model['connection'], 2);
        $this->assertFalse(isset($model['table']));
        $this->assertEquals($model['table'], null);
        $this->assertFalse(isset($model['with']));
    }

    public function testOnly()
    {
        $model = new ModelStub;
        $model->first_name = 'taylor';
        $model->last_name = 'otwell';
        $model->project = 'laravel';

        $this->assertEquals(['project' => 'laravel'], $model->only('project'));
        $this->assertEquals(['first_name' => 'taylor', 'last_name' => 'otwell'], $model->only('first_name', 'last_name'));
        $this->assertEquals(['first_name' => 'taylor', 'last_name' => 'otwell'], $model->only(['first_name', 'last_name']));
    }

    public function testNewInstanceReturnsNewInstanceWithAttributesSet()
    {
        $model = new ModelStub;
        $instance = $model->newInstance(['name' => 'taylor']);
        $this->assertInstanceOf(ModelStub::class, $instance);
        $this->assertEquals('taylor', $instance->name);
    }

    public function testNewInstanceReturnsNewInstanceWithTableSet()
    {
        $model = new ModelStub;
        $model->setTable('test');
        $newInstance = $model->newInstance();

        $this->assertEquals('test', $newInstance->getTable());
    }

    public function testCreateMethodSavesNewModel()
    {
        $_SERVER['__model.saved'] = false;
        $model = ModelSaveStub::create(['name' => 'taylor']);
        $this->assertTrue($_SERVER['__model.saved']);
        $this->assertEquals('taylor', $model->name);
    }

    public function testMakeMethodDoesNotSaveNewModel()
    {
        $_SERVER['__model.saved'] = false;
        $model = ModelSaveStub::make(['name' => 'taylor']);
        $this->assertFalse($_SERVER['__model.saved']);
        $this->assertEquals('taylor', $model->name);
    }

    public function testForceCreateMethodSavesNewModelWithGuardedAttributes()
    {
        $_SERVER['__model.saved'] = false;
        $model = ModelSaveStub::forceCreate(['id' => 21]);
        $this->assertTrue($_SERVER['__model.saved']);
        $this->assertEquals(21, $model->id);
    }

    public function testFindMethodUseWritePdo()
    {
        ModelFindWithWritePdoStub::onWriteConnection()->find(1);
    }

    public function testDestroyMethodCallsQueryBuilderCorrectly()
    {
        ModelDestroyStub::destroy(1, 2, 3);
    }

    public function testDestroyMethodCallsQueryBuilderCorrectlyWithCollection()
    {
        ModelDestroyStub::destroy(new Collection([1, 2, 3]));
    }

    public function testWithMethodCallsQueryBuilderCorrectly()
    {
        $result = ModelWithStub::with('foo', 'bar');
        $this->assertEquals('foo', $result);
    }

    public function testWithoutMethodRemovesEagerLoadedRelationshipCorrectly()
    {
        $model = new ModelWithoutRelationStub();
        $this->addMockConnection($model);
        $instance = $model->newInstance()->newQuery()->without('foo');
        $this->assertEmpty($instance->getEagerLoads());
    }

    public function testEagerLoadingWithColumns()
    {
        $model = new ModelWithoutRelationStub();
        $instance = $model->newInstance()->newQuery()->with('foo:bar,baz', 'hadi');
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('select')->once()->with(['bar', 'baz']);
        $this->assertNotNull($instance->getEagerLoads()['hadi']);
        $this->assertNotNull($instance->getEagerLoads()['foo']);
        $closure = $instance->getEagerLoads()['foo'];
        $closure($builder);
    }

    public function testWithMethodCallsQueryBuilderCorrectlyWithArray()
    {
        $result = ModelWithStub::with(['foo', 'bar']);
        $this->assertEquals('foo', $result);
    }

    public function testUpdateProcess()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('id', '=', 1);
        $query->shouldReceive('update')->once()->with(['name' => 'taylor'])->andReturn(1);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');
        Register::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('until')->once()->with('model.updating: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('model.updated: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('model.saved: '.get_class($model), $model)->andReturn(true);

        $model->id = 1;
        $model->foo = 'bar';
        // make sure foo isn't synced so we can test that dirty attributes only are updated
        $model->syncOriginal();
        $model->name = 'taylor';
        $model->exists = true;
        $this->assertTrue($model->save());
    }

    public function testUpdateProcessDoesntOverrideTimestamps()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('id', '=', 1);
        $query->shouldReceive('update')->once()->with(['created_at' => 'foo', 'updated_at' => 'bar'])->andReturn(1);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        Register::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until');
        $events->shouldReceive('dispatch');

        $model->id = 1;
        $model->syncOriginal();
        $model->created_at = 'foo';
        $model->updated_at = 'bar';
        $model->exists = true;
        $this->assertTrue($model->save());
    }

    public function testSaveIsCancelledIfSavingEventReturnsFalse()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery'])->getMock();
        $query = Mockery::mock(Builder::class);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(false);
        $model->exists = true;

        $this->assertFalse($model->save());
    }

    public function testUpdateIsCancelledIfUpdatingEventReturnsFalse()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery'])->getMock();
        $query = Mockery::mock(Builder::class);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('until')->once()->with('model.updating: '.get_class($model), $model)->andReturn(false);
        $model->exists = true;
        $model->foo = 'bar';

        $this->assertFalse($model->save());
    }

    public function testEventsCanBeFiredWithCustomEventObjects()
    {
        $model = $this->getMockBuilder(ModelEventObjectStub::class)->setMethods(['newModelQuery'])->getMock();
        $query = Mockery::mock(Builder::class);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        Register::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with(Mockery::type(ModelSavingEventStub::class))->andReturn(false);
        $model->exists = true;

        $this->assertFalse($model->save());
    }

    public function testUpdateProcessWithoutTimestamps()
    {
        $model = $this->getMockBuilder(ModelEventObjectStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'fireModelEvent'])->getMock();
        $model->timestamps = false;
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('id', '=', 1);
        $query->shouldReceive('update')->once()->with(['name' => 'taylor'])->andReturn(1);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->never())->method('updateTimestamps');
        $model->expects($this->any())->method('fireModelEvent')->will($this->returnValue(true));

        $model->id = 1;
        $model->syncOriginal();
        $model->name = 'taylor';
        $model->exists = true;
        $this->assertTrue($model->save());
    }

    public function testUpdateUsesOldPrimaryKey()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('id', '=', 1);
        $query->shouldReceive('update')->once()->with(['id' => 2, 'foo' => 'bar'])->andReturn(1);
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');
        $model->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('until')->once()->with('model.updating: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('model.updated: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('model.saved: '.get_class($model), $model)->andReturn(true);

        $model->id = 1;
        $model->syncOriginal();
        $model->id = 2;
        $model->foo = 'bar';
        $model->exists = true;

        $this->assertTrue($model->save());
    }

    public function testTimestampsAreReturnedAsObjects()
    {
        $model = $this->getMockBuilder(DateModelStub::class)->setMethods(['getDateFormat'])->getMock();
        $model->expects($this->any())->method('getDateFormat')->will($this->returnValue('Y-m-d'));
        $model->setRawAttributes([
            'created_at' => '2012-12-04',
            'updated_at' => '2012-12-05',
        ]);

        $this->assertInstanceOf(Carbon::class, $model->created_at);
        $this->assertInstanceOf(Carbon::class, $model->updated_at);
    }

    public function testTimestampsAreReturnedAsObjectsFromPlainDatesAndTimestamps()
    {
        $model = $this->getMockBuilder(DateModelStub::class)->setMethods(['getDateFormat'])->getMock();
        $model->expects($this->any())->method('getDateFormat')->will($this->returnValue('Y-m-d H:i:s'));
        $model->setRawAttributes([
            'created_at' => '2012-12-04',
            'updated_at' => $this->currentTime(),
        ]);

        $this->assertInstanceOf(Carbon::class, $model->created_at);
        $this->assertInstanceOf(Carbon::class, $model->updated_at);
    }

    public function testTimestampsAreReturnedAsObjectsOnCreate()
    {
        $timestamps = [
            'created_at' =>Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
        $model = new DateModelStub;
        Model::setConnectionResolver($resolver = Mockery::mock(ConnectionResolverInterface::class));
        $resolver->shouldReceive('connection')->andReturn($mockConnection = Mockery::mock(stdClass::class));
        $mockConnection->shouldReceive('getQueryGrammar')->andReturn($mockConnection);
        $mockConnection->shouldReceive('getDateFormat')->andReturn('Y-m-d H:i:s');
        $instance = $model->newInstance($timestamps);
        $this->assertInstanceOf(Carbon::class, $instance->updated_at);
        $this->assertInstanceOf(Carbon::class, $instance->created_at);
    }

    public function testDateTimeAttributesReturnNullIfSetToNull()
    {
        $timestamps = [
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
        $model = new DateModelStub;
        Model::setConnectionResolver($resolver = Mockery::mock(ConnectionResolverInterface::class));
        $resolver->shouldReceive('connection')->andReturn($mockConnection = Mockery::mock(stdClass::class));
        $mockConnection->shouldReceive('getQueryGrammar')->andReturn($mockConnection);
        $mockConnection->shouldReceive('getDateFormat')->andReturn('Y-m-d H:i:s');
        $instance = $model->newInstance($timestamps);

        $instance->created_at = null;
        $this->assertNull($instance->created_at);
    }

    public function testTimestampsAreCreatedFromStringsAndIntegers()
    {
        $model = new DateModelStub;
        $model->created_at = '2013-05-22 00:00:00';
        $this->assertInstanceOf(Carbon::class, $model->created_at);

        $model = new DateModelStub;
        $model->created_at = $this->currentTime();
        $this->assertInstanceOf(Carbon::class, $model->created_at);

        $model = new DateModelStub;
        $model->created_at = 0;
        $this->assertInstanceOf(Carbon::class, $model->created_at);

        $model = new DateModelStub;
        $model->created_at = '2012-01-01';
        $this->assertInstanceOf(Carbon::class, $model->created_at);
    }

    public function testFromDateTime()
    {
        $model = new ModelStub;

        $value = Carbon::parse('2015-04-17 22:59:01');
        $this->assertEquals('2015-04-17 22:59:01', $model->fromDateTime($value));

        $value = new DateTime('2015-04-17 22:59:01');
        $this->assertInstanceOf(DateTime::class, $value);
        $this->assertInstanceOf(DateTimeInterface::class, $value);
        $this->assertEquals('2015-04-17 22:59:01', $model->fromDateTime($value));

        $value = new DateTimeImmutable('2015-04-17 22:59:01');
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertInstanceOf(DateTimeInterface::class, $value);
        $this->assertEquals('2015-04-17 22:59:01', $model->fromDateTime($value));

        $value = '2015-04-17 22:59:01';
        $this->assertEquals('2015-04-17 22:59:01', $model->fromDateTime($value));

        $value = '2015-04-17';
        $this->assertEquals('2015-04-17 00:00:00', $model->fromDateTime($value));

        $value = '2015-4-17';
        $this->assertEquals('2015-04-17 00:00:00', $model->fromDateTime($value));

        $value = '1429311541';
        $this->assertEquals('2015-04-17 22:59:01', $model->fromDateTime($value));

        $this->assertNull($model->fromDateTime(null));
    }

    public function testInsertProcess()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');

        $model->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('until')->once()->with('model.creating: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('model.created: '.get_class($model), $model);
        $events->shouldReceive('dispatch')->once()->with('model.saved: '.get_class($model), $model);

        $model->name = 'taylor';
        $model->exists = false;
        $this->assertTrue($model->save());
        $this->assertEquals(1, $model->id);
        $this->assertTrue($model->exists);

        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insert')->once()->with(['name' => 'taylor']);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');
        $model->setIncrementing(false);

        $model->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('until')->once()->with('model.creating: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('model.created: '.get_class($model), $model);
        $events->shouldReceive('dispatch')->once()->with('model.saved: '.get_class($model), $model);

        $model->name = 'taylor';
        $model->exists = false;
        $this->assertTrue($model->save());
        $this->assertNull($model->id);
        $this->assertTrue($model->exists);
    }

    public function testInsertIsCancelledIfCreatingEventReturnsFalse()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('until')->once()->with('model.saving: '.get_class($model), $model)->andReturn(true);
        $events->shouldReceive('until')->once()->with('model.creating: '.get_class($model), $model)->andReturn(false);

        $this->assertFalse($model->save());
        $this->assertFalse($model->exists);
    }

    public function testDeleteProperlyDeletesModel()
    {
        $model = $this->getMockBuilder(Model::class)->setMethods(['newModelQuery', 'updateTimestamps', 'touchOwners'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('id', '=', 1)->andReturn($query);
        $query->shouldReceive('delete')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('touchOwners');
        $model->exists = true;
        $model->id = 1;
        $model->delete();
    }

    public function testPushNoRelations()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');

        $model->name = 'taylor';
        $model->exists = false;

        $this->assertTrue($model->push());
        $this->assertEquals(1, $model->id);
        $this->assertTrue($model->exists);
    }

    public function testPushEmptyOneRelation()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');

        $model->name = 'taylor';
        $model->exists = false;
        $model->setRelation('relationOne', null);

        $this->assertTrue($model->push());
        $this->assertEquals(1, $model->id);
        $this->assertTrue($model->exists);
        $this->assertNull($model->relationOne);
    }

    public function testPushOneRelation()
    {
        $related1 = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'related1'], 'id')->andReturn(2);
        $query->shouldReceive('getConnection')->once();
        $related1->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $related1->expects($this->once())->method('updateTimestamps');
        $related1->name = 'related1';
        $related1->exists = false;

        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');

        $model->name = 'taylor';
        $model->exists = false;
        $model->setRelation('relationOne', $related1);

        $this->assertTrue($model->push());
        $this->assertEquals(1, $model->id);
        $this->assertTrue($model->exists);
        $this->assertEquals(2, $model->relationOne->id);
        $this->assertTrue($model->relationOne->exists);
        $this->assertEquals(2, $related1->id);
        $this->assertTrue($related1->exists);
    }

    public function testPushEmptyManyRelation()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');

        $model->name = 'taylor';
        $model->exists = false;
        $model->setRelation('relationMany', new Collection([]));

        $this->assertTrue($model->push());
        $this->assertEquals(1, $model->id);
        $this->assertTrue($model->exists);
        $this->assertCount(0, $model->relationMany);
    }

    public function testPushManyRelation()
    {
        $related1 = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'related1'], 'id')->andReturn(2);
        $query->shouldReceive('getConnection')->once();
        $related1->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $related1->expects($this->once())->method('updateTimestamps');
        $related1->name = 'related1';
        $related1->exists = false;

        $related2 = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'related2'], 'id')->andReturn(3);
        $query->shouldReceive('getConnection')->once();
        $related2->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $related2->expects($this->once())->method('updateTimestamps');
        $related2->name = 'related2';
        $related2->exists = false;

        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));
        $model->expects($this->once())->method('updateTimestamps');

        $model->name = 'taylor';
        $model->exists = false;
        $model->setRelation('relationMany', new Collection([$related1, $related2]));

        $this->assertTrue($model->push());
        $this->assertEquals(1, $model->id);
        $this->assertTrue($model->exists);
        $this->assertCount(2, $model->relationMany);
        $this->assertEquals([2, 3], $model->relationMany->pluck('id')->all());
    }

    public function testNewQueryReturnsQueryBuilder()
    {
        $conn = Mockery::mock(Connection::class);
        $grammar = Mockery::mock(Grammar::class);
        $processor = Mockery::mock(Processor::class);
        $conn->shouldReceive('getQueryGrammar')->once()->andReturn($grammar);
        $conn->shouldReceive('getPostProcessor')->once()->andReturn($processor);
        Register::setConnectionResolver($resolver = Mockery::mock(ConnectionResolverInterface::class));
        $resolver->shouldReceive('connection')->andReturn($conn);
        $model = new ModelStub;
        $builder = $model->newQuery();
        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testGetAndSetTableOperations()
    {
        $model = new ModelStub;
        $this->assertEquals('stub', $model->getTable());
        $model->setTable('foo');
        $this->assertEquals('foo', $model->getTable());
    }

    public function testGetKeyReturnsValueOfPrimaryKey()
    {
        $model = new ModelStub;
        $model->id = 1;
        $this->assertEquals(1, $model->getKey());
        $this->assertEquals('id', $model->getKeyName());
    }

    public function testConnectionManagement()
    {
        Register::setConnectionResolver($resolver = Mockery::mock(ConnectionResolverInterface::class));
        $model = Mockery::mock(ModelStub::class.'[getConnectionName,connection]');

        $retval = $model->setConnection('foo');
        $this->assertEquals($retval, $model);
        $this->assertEquals('foo', $model->connection);

        $model->shouldReceive('getConnectionName')->once()->andReturn('somethingElse');
        $resolver->shouldReceive('connection')->once()->with('somethingElse')->andReturn('bar');

        $this->assertEquals('bar', $model->getConnection());
    }

    public function testToArray()
    {
        $model = new ModelStub;
        $model->name = 'foo';
        $model->age = null;
        $model->password = 'password1';
        $model->setHidden(['password']);
        $model->setRelation('names', new BaseCollection([
            new ModelStub(['bar' => 'baz']), new ModelStub(['bam' => 'boom']),
        ]));
        $model->setRelation('partner', new ModelStub(['name' => 'abby']));
        $model->setRelation('group', null);
        $model->setRelation('multi', new BaseCollection);
        $array = $model->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('foo', $array['name']);
        $this->assertEquals('baz', $array['names'][0]['bar']);
        $this->assertEquals('boom', $array['names'][1]['bam']);
        $this->assertEquals('abby', $array['partner']['name']);
        $this->assertNull($array['group']);
        $this->assertEquals([], $array['multi']);
        $this->assertFalse(isset($array['password']));

        $model->setAppends(['appendable']);
        $array = $model->toArray();
        $this->assertEquals('appended', $array['appendable']);
    }

    public function testVisibleCreatesArrayWhitelist()
    {
        $model = new ModelStub;
        $model->setVisible(['name']);
        $model->name = 'Taylor';
        $model->age = 26;
        $array = $model->toArray();

        $this->assertEquals(['name' => 'Taylor'], $array);
    }

    public function testHiddenCanAlsoExcludeRelationships()
    {
        $model = new ModelStub;
        $model->name = 'Taylor';
        $model->setRelation('foo', ['bar']);
        $model->setHidden(['foo', 'list_items', 'password']);
        $array = $model->toArray();

        $this->assertEquals(['name' => 'Taylor'], $array);
    }

    public function testGetArrayableRelationsFunctionExcludeHiddenRelationships()
    {
        $model = new ModelStub;

        $class = new ReflectionClass($model);
        $method = $class->getMethod('getArrayableRelations');
        $method->setAccessible(true);

        $model->setRelation('foo', ['bar']);
        $model->setRelation('bam', ['boom']);
        $model->setHidden(['foo']);

        $array = $method->invokeArgs($model, []);

        $this->assertSame(['bam' => ['boom']], $array);
    }

    public function testToArraySnakeAttributes()
    {
        $model = new ModelStub;
        $model->setRelation('namesList', new BaseCollection([
            new ModelStub(['bar' => 'baz']), new ModelStub(['bam' => 'boom']),
        ]));
        $array = $model->toArray();

        $this->assertEquals('baz', $array['names_list'][0]['bar']);
        $this->assertEquals('boom', $array['names_list'][1]['bam']);

        $model = new ModelCamelStub();
        $model->setRelation('namesList', new BaseCollection([
            new ModelStub(['bar' => 'baz']), new ModelStub(['bam' => 'boom']),
        ]));
        $array = $model->toArray();

        $this->assertEquals('baz', $array['namesList'][0]['bar']);
        $this->assertEquals('boom', $array['namesList'][1]['bam']);
    }

    public function testToArrayUsesMutators()
    {
        $model = new ModelStub;
        $model->list_items = [1, 2, 3];
        $array = $model->toArray();

        $this->assertEquals([1, 2, 3], $array['list_items']);
    }

    public function testHidden()
    {
        $model = new ModelStub(['name' => 'foo', 'age' => 'bar', 'id' => 'baz']);
        $model->setHidden(['age', 'id']);
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('age', $array);
    }

    public function testVisible()
    {
        $model = new ModelStub(['name' => 'foo', 'age' => 'bar', 'id' => 'baz']);
        $model->setVisible(['name', 'id']);
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('age', $array);
    }

    public function testDynamicHidden()
    {
        $model = new ModelDynamicHiddenStub(['name' => 'foo', 'age' => 'bar', 'id' => 'baz']);
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('age', $array);
    }

    public function testWithHidden()
    {
        $model = new ModelStub(['name' => 'foo', 'age' => 'bar', 'id' => 'baz']);
        $model->setHidden(['age', 'id']);
        $model->makeVisible('age');
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('age', $array);
        $this->assertArrayNotHasKey('id', $array);
    }

    public function testMakeHidden()
    {
        $model = new ModelStub(['name' => 'foo', 'age' => 'bar', 'address' => 'foobar', 'id' => 'baz']);
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('age', $array);
        $this->assertArrayHasKey('address', $array);
        $this->assertArrayHasKey('id', $array);

        $array = $model->makeHidden('address')->toArray();
        $this->assertArrayNotHasKey('address', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('age', $array);
        $this->assertArrayHasKey('id', $array);

        $array = $model->makeHidden(['name', 'age'])->toArray();
        $this->assertArrayNotHasKey('name', $array);
        $this->assertArrayNotHasKey('age', $array);
        $this->assertArrayNotHasKey('address', $array);
        $this->assertArrayHasKey('id', $array);
    }

    public function testDynamicVisible()
    {
        $model = new ModelDynamicVisibleStub(['name' => 'foo', 'age' => 'bar', 'id' => 'baz']);
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('age', $array);
    }

    public function testFillable()
    {
        $model = new ModelStub;
        $model->fillable(['name', 'age']);
        $model->fill(['name' => 'foo', 'age' => 'bar']);
        $this->assertEquals('foo', $model->name);
        $this->assertEquals('bar', $model->age);
    }

    public function testQualifyColumn()
    {
        $model = new ModelStub;

        $this->assertEquals('stub.column', $model->qualifyColumn('column'));
    }

    public function testForceFillMethodFillsGuardedAttributes()
    {
        $model = (new ModelSaveStub)->forceFill(['id' => 21]);
        $this->assertEquals(21, $model->id);
    }

    public function testFillingJSONAttributes()
    {
        $model = new ModelStub;
        $model->fillable(['meta->name', 'meta->price', 'meta->size->width']);
        $model->fill(['meta->name' => 'foo', 'meta->price' => 'bar', 'meta->size->width' => 'baz']);
        $this->assertEquals(
            ['meta' => json_encode(['name' => 'foo', 'price' => 'bar', 'size' => ['width' => 'baz']])],
            $model->toArray()
        );

        $model = new ModelStub(['meta' => json_encode(['name' => 'Taylor'])]);
        $model->fillable(['meta->name', 'meta->price', 'meta->size->width']);
        $model->fill(['meta->name' => 'foo', 'meta->price' => 'bar', 'meta->size->width' => 'baz']);
        $this->assertEquals(
            ['meta' => json_encode(['name' => 'foo', 'price' => 'bar', 'size' => ['width' => 'baz']])],
            $model->toArray()
        );
    }

    public function testUnguardAllowsAnythingToBeSet()
    {
        $model = new ModelStub;
        ModelStub::unguard();
        $model->guard(['*']);
        $model->fill(['name' => 'foo', 'age' => 'bar']);
        $this->assertEquals('foo', $model->name);
        $this->assertEquals('bar', $model->age);
        ModelStub::unguard(false);
    }

    public function testUnderscorePropertiesAreNotFilled()
    {
        $model = new ModelStub;
        $model->fill(['_method' => 'PUT']);
        $this->assertEquals([], $model->getAttributes());
    }

    public function testGuarded()
    {
        $model = new ModelStub;
        $model->guard(['name', 'age']);
        $model->fill(['name' => 'foo', 'age' => 'bar', 'foo' => 'bar']);
        $this->assertFalse(isset($model->name));
        $this->assertFalse(isset($model->age));
        $this->assertEquals('bar', $model->foo);
    }

    public function testFillableOverridesGuarded()
    {
        $model = new ModelStub;
        $model->guard(['name', 'age']);
        $model->fillable(['age', 'foo']);
        $model->fill(['name' => 'foo', 'age' => 'bar', 'foo' => 'bar']);
        $this->assertFalse(isset($model->name));
        $this->assertEquals('bar', $model->age);
        $this->assertEquals('bar', $model->foo);
    }

    /**
     * @expectedException \Illuminate\Database\\MassAssignmentException
     * @expectedExceptionMessage name
     */
    public function testGlobalGuarded()
    {
        $model = new ModelStub;
        $model->guard(['*']);
        $model->fill(['name' => 'foo', 'age' => 'bar', 'votes' => 'baz']);
    }

    public function testUnguardedRunsCallbackWhileBeingUnguarded()
    {
        $model = Model::unguarded(function () {
            return (new ModelStub)->guard(['*'])->fill(['name' => 'Taylor']);
        });
        $this->assertEquals('Taylor', $model->name);
        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedCallDoesNotChangeUnguardedState()
    {
        Model::unguard();
        $model = Model::unguarded(function () {
            return (new ModelStub)->guard(['*'])->fill(['name' => 'Taylor']);
        });
        $this->assertEquals('Taylor', $model->name);
        $this->assertTrue(Model::isUnguarded());
        Model::reguard();
    }

    public function testUnguardedCallDoesNotChangeUnguardedStateOnException()
    {
        try {
            Model::unguarded(function () {
                throw new Exception;
            });
        } catch (Exception $e) {
            // ignore the exception
        }
        $this->assertFalse(Model::isUnguarded());
    }

    public function testHasOneCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->hasOne(ModelSaveStub::class);
        $this->assertEquals('save_stub.model_stub_id', $relation->getQualifiedForeignKeyName());

        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->hasOne(ModelSaveStub::class, 'foo');
        $this->assertEquals('save_stub.foo', $relation->getQualifiedForeignKeyName());
        $this->assertSame($model, $relation->getParent());
        $this->assertInstanceOf(ModelSaveStub::class, $relation->getQuery()->getModel());
    }

    public function testMorphOneCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->morphOne(ModelSaveStub::class, 'morph');
        $this->assertEquals('save_stub.morph_id', $relation->getQualifiedForeignKeyName());
        $this->assertEquals('save_stub.morph_type', $relation->getQualifiedMorphType());
        $this->assertEquals(ModelStub::class, $relation->getMorphClass());
    }

    public function testCorrectMorphClassIsReturned()
    {
        Relation::morphMap(['alias' => 'AnotherModel']);
        $model = new ModelStub;

        try {
            $this->assertEquals(ModelStub::class, $model->getMorphClass());
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function testHasManyCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->hasMany(ModelSaveStub::class);
        $this->assertEquals('save_stub.model_stub_id', $relation->getQualifiedForeignKeyName());

        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->hasMany(ModelSaveStub::class, 'foo');

        $this->assertEquals('save_stub.foo', $relation->getQualifiedForeignKeyName());
        $this->assertSame($model, $relation->getParent());
        $this->assertInstanceOf(ModelSaveStub::class, $relation->getQuery()->getModel());
    }

    public function testMorphManyCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->morphMany(ModelSaveStub::class, 'morph');
        $this->assertEquals('save_stub.morph_id', $relation->getQualifiedForeignKeyName());
        $this->assertEquals('save_stub.morph_type', $relation->getQualifiedMorphType());
        $this->assertEquals(ModelStub::class, $relation->getMorphClass());
    }

    public function testBelongsToCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->belongsToStub();
        $this->assertEquals('belongs_to_stub_id', $relation->getForeignKeyName());
        $this->assertSame($model, $relation->getParent());
        $this->assertInstanceOf(ModelSaveStub::class, $relation->getQuery()->getModel());

        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->belongsToExplicitKeyStub();
        $this->assertEquals('foo', $relation->getForeignKeyName());
    }

    public function testMorphToCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);

        // $this->morphTo();
        $relation = $model->morphToStub();
        $this->assertEquals('morph_to_stub_id', $relation->getForeignKeyName());
        $this->assertEquals('morph_to_stub_type', $relation->getMorphType());
        $this->assertEquals('morphToStub', $relation->getRelationName());
        $this->assertSame($model, $relation->getParent());
        $this->assertInstanceOf(ModelSaveStub::class, $relation->getQuery()->getModel());

        // $this->morphTo(null, 'type', 'id');
        $relation2 = $model->morphToStubWithKeys();
        $this->assertEquals('id', $relation2->getForeignKeyName());
        $this->assertEquals('type', $relation2->getMorphType());
        $this->assertEquals('morphToStubWithKeys', $relation2->getRelationName());

        // $this->morphTo('someName');
        $relation3 = $model->morphToStubWithName();
        $this->assertEquals('some_name_id', $relation3->getForeignKeyName());
        $this->assertEquals('some_name_type', $relation3->getMorphType());
        $this->assertEquals('someName', $relation3->getRelationName());

        // $this->morphTo('someName', 'type', 'id');
        $relation4 = $model->morphToStubWithNameAndKeys();
        $this->assertEquals('id', $relation4->getForeignKeyName());
        $this->assertEquals('type', $relation4->getMorphType());
        $this->assertEquals('someName', $relation4->getRelationName());
    }

    public function testBelongsToManyCreatesProperRelation()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);

        $relation = $model->belongsToMany(ModelSaveStub::class);
        $this->assertEquals('model_save_stub_model_stub.model_stub_id', $relation->getQualifiedForeignPivotKeyName());
        $this->assertEquals('model_save_stub_model_stub.model_save_stub_id', $relation->getQualifiedRelatedPivotKeyName());
        $this->assertSame($model, $relation->getParent());
        $this->assertInstanceOf(ModelSaveStub::class, $relation->getQuery()->getModel());
        $this->assertEquals(__FUNCTION__, $relation->getRelationName());

        $model = new ModelStub;
        $this->addMockConnection($model);
        $relation = $model->belongsToMany(ModelSaveStub::class, 'table', 'foreign', 'other');
        $this->assertEquals('table.foreign', $relation->getQualifiedForeignPivotKeyName());
        $this->assertEquals('table.other', $relation->getQualifiedRelatedPivotKeyName());
        $this->assertSame($model, $relation->getParent());
        $this->assertInstanceOf(ModelSaveStub::class, $relation->getQuery()->getModel());
    }

    public function testRelationsWithVariedConnections()
    {
        // Has one
        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->hasOne(NoConnectionModelStub::class);
        $this->assertEquals('non_default', $relation->getRelated()->getConnectionName());

        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->hasOne(DifferentConnectionModelStub::class);
        $this->assertEquals('different_connection', $relation->getRelated()->getConnectionName());

        // Morph One
        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->morphOne(NoConnectionModelStub::class, 'type');
        $this->assertEquals('non_default', $relation->getRelated()->getConnectionName());

        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->morphOne(DifferentConnectionModelStub::class, 'type');
        $this->assertEquals('different_connection', $relation->getRelated()->getConnectionName());

        // Belongs to
        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->belongsTo(NoConnectionModelStub::class);
        $this->assertEquals('non_default', $relation->getRelated()->getConnectionName());

        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->belongsTo(DifferentConnectionModelStub::class);
        $this->assertEquals('different_connection', $relation->getRelated()->getConnectionName());

        // has many
        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->hasMany(NoConnectionModelStub::class);
        $this->assertEquals('non_default', $relation->getRelated()->getConnectionName());

        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->hasMany(DifferentConnectionModelStub::class);
        $this->assertEquals('different_connection', $relation->getRelated()->getConnectionName());

        // has many through
        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->hasManyThrough(NoConnectionModelStub::class, ModelSaveStub::class);
        $this->assertEquals('non_default', $relation->getRelated()->getConnectionName());

        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->hasManyThrough(DifferentConnectionModelStub::class, ModelSaveStub::class);
        $this->assertEquals('different_connection', $relation->getRelated()->getConnectionName());

        // belongs to many
        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->belongsToMany(NoConnectionModelStub::class);
        $this->assertEquals('non_default', $relation->getRelated()->getConnectionName());

        $model = new ModelStub;
        $model->setConnection('non_default');
        $this->addMockConnection($model);
        $relation = $model->belongsToMany(DifferentConnectionModelStub::class);
        $this->assertEquals('different_connection', $relation->getRelated()->getConnectionName());
    }

    public function testModelsAssumeTheirName()
    {
        $model = new ModelWithoutTableStub();
        $this->assertEquals('model_without_table_stubs', $model->getTable());

        $namespacedModel = new ModelNamespacedStub();
        $this->assertEquals('model_namespaced_stubs', $namespacedModel->getTable());
    }

    public function testTheMutatorCacheIsPopulated()
    {
        $class = new ModelStub;

        $expectedAttributes = [
            'list_items',
            'password',
            'appendable',
        ];

        $this->assertEquals($expectedAttributes, $class->getMutatedAttributes());
    }

    public function testRouteKeyIsPrimaryKey()
    {
        $model = new ModelNonIncrementingStub;
        $model->id = 'foo';
        $this->assertEquals('foo', $model->getRouteKey());
    }

    public function testRouteNameIsPrimaryKeyName()
    {
        $model = new ModelStub;
        $this->assertEquals('id', $model->getRouteKeyName());
    }

    public function testCloneModelMakesAFreshCopyOfTheModel()
    {
        $class = new ModelStub;
        $class->id = 1;
        $class->exists = true;
        $class->first = 'taylor';
        $class->last = 'otwell';
        $class->created_at = $class->freshTimestamp();
        $class->updated_at = $class->freshTimestamp();
        $class->setRelation('foo', ['bar']);

        $clone = $class->replicate();

        $this->assertNull($clone->id);
        $this->assertFalse($clone->exists);
        $this->assertEquals('taylor', $clone->first);
        $this->assertEquals('otwell', $clone->last);
        $this->assertObjectNotHasAttribute('created_at', $clone);
        $this->assertObjectNotHasAttribute('updated_at', $clone);
        $this->assertEquals(['bar'], $clone->foo);
    }

    public function testModelObserversCanBeAttachedToModels()
    {
        ModelStub::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('listen')->once()->with('model.creating: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@creating');
        $events->shouldReceive('listen')->once()->with('model.saved: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@saved');
        $events->shouldReceive('forget');
        ModelStub::observe(new TestObserverStub);
        ModelStub::flushEventListeners();
    }

    public function testModelObserversCanBeAttachedToModelsWithString()
    {
        ModelStub::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('listen')->once()->with('model.creating: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@creating');
        $events->shouldReceive('listen')->once()->with('model.saved: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@saved');
        $events->shouldReceive('forget');
        ModelStub::observe(TestObserverStub::class);
        ModelStub::flushEventListeners();
    }

    public function testModelObserversCanBeAttachedToModelsThroughAnArray()
    {
        ModelStub::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('listen')->once()->with('model.creating: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@creating');
        $events->shouldReceive('listen')->once()->with('model.saved: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@saved');
        $events->shouldReceive('forget');
        ModelStub::observe([TestObserverStub::class]);
        ModelStub::flushEventListeners();
    }

    public function testModelObserversCanBeAttachedToModelsThroughCallingObserveMethodOnlyOnce()
    {
        ModelStub::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('listen')->once()->with('model.creating: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@creating');
        $events->shouldReceive('listen')->once()->with('model.saved: Illuminate\Tests\Database\ModelStub', TestObserverStub::class.'@saved');

        $events->shouldReceive('listen')->once()->with('model.creating: Illuminate\Tests\Database\ModelStub', TestAnotherObserverStub::class.'@creating');
        $events->shouldReceive('listen')->once()->with('model.saved: Illuminate\Tests\Database\ModelStub', TestAnotherObserverStub::class.'@saved');

        $events->shouldReceive('forget');

        ModelStub::observe([
            TestObserverStub::class,
            TestAnotherObserverStub::class,
        ]);

        ModelStub::flushEventListeners();
    }

    public function testSetObservableEvents()
    {
        $class = new ModelStub;
        $class->setObservableEvents(['foo']);

        $this->assertContains('foo', $class->getObservableEvents());
    }

    public function testAddObservableEvent()
    {
        $class = new ModelStub;
        $class->addObservableEvents('foo');

        $this->assertContains('foo', $class->getObservableEvents());
    }

    public function testAddMultipleObserveableEvents()
    {
        $class = new ModelStub;
        $class->addObservableEvents('foo', 'bar');

        $this->assertContains('foo', $class->getObservableEvents());
        $this->assertContains('bar', $class->getObservableEvents());
    }

    public function testRemoveObservableEvent()
    {
        $class = new ModelStub;
        $class->setObservableEvents(['foo', 'bar']);
        $class->removeObservableEvents('bar');

        $this->assertNotContains('bar', $class->getObservableEvents());
    }

    public function testRemoveMultipleObservableEvents()
    {
        $class = new ModelStub;
        $class->setObservableEvents(['foo', 'bar']);
        $class->removeObservableEvents('foo', 'bar');

        $this->assertNotContains('foo', $class->getObservableEvents());
        $this->assertNotContains('bar', $class->getObservableEvents());
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Illuminate\Tests\Database\ModelStub::incorrectRelationStub must return a relationship instance.
     */
    public function testGetModelAttributeMethodThrowsExceptionIfNotRelation()
    {
        $model = new ModelStub;
        $model->incorrectRelationStub;
    }

    public function testModelIsBootedOnUnserialize()
    {
        $model = new ModelBootingTestStub;
        $this->assertTrue(ModelBootingTestStub::isBooted());
        $model->foo = 'bar';
        $string = serialize($model);
        $model = null;
        ModelBootingTestStub::unboot();
        $this->assertFalse(ModelBootingTestStub::isBooted());
        unserialize($string);
        $this->assertTrue(ModelBootingTestStub::isBooted());
    }

    public function testModelsTraitIsInitialized()
    {
        $model = new ModelStubWithTrait;
        $this->assertTrue($model->fooBarIsInitialized);
    }

    public function testAppendingOfAttributes()
    {
        $model = new ModelAppendsStub;

        $this->assertTrue(isset($model->is_admin));
        $this->assertTrue(isset($model->camelCased));
        $this->assertTrue(isset($model->StudlyCased));

        $this->assertEquals('admin', $model->is_admin);
        $this->assertEquals('camelCased', $model->camelCased);
        $this->assertEquals('StudlyCased', $model->StudlyCased);

        $model->setHidden(['is_admin', 'camelCased', 'StudlyCased']);
        $this->assertEquals([], $model->toArray());

        $model->setVisible([]);
        $this->assertEquals([], $model->toArray());
    }

    public function testGetMutatedAttributes()
    {
        $model = new ModelGetMutatorsStub;

        $this->assertEquals(['first_name', 'middle_name', 'last_name'], $model->getMutatedAttributes());

        ModelGetMutatorsStub::resetMutatorCache();

        ModelGetMutatorsStub::$snakeAttributes = false;
        $this->assertEquals(['firstName', 'middleName', 'lastName'], $model->getMutatedAttributes());
    }

    public function testReplicateCreatesANewModelInstanceWithSameAttributeValues()
    {
        $model = new ModelStub;
        $model->id = 'id';
        $model->foo = 'bar';
        $model->created_at = new DateTime;
        $model->updated_at = new DateTime;
        $replicated = $model->replicate();

        $this->assertNull($replicated->id);
        $this->assertEquals('bar', $replicated->foo);
        $this->assertNull($replicated->created_at);
        $this->assertNull($replicated->updated_at);
    }

    public function testIncrementOnExistingModelCallsQueryAndSetsAttribute()
    {
        $model = Mockery::mock(ModelStub::class.'[newModelQuery]');
        $model->exists = true;
        $model->id = 1;
        $model->syncOriginalAttribute('id');
        $model->foo = 2;

        $model->shouldReceive('newModelQuery')->andReturn($query = Mockery::mock(stdClass::class));
        $query->shouldReceive('where')->andReturn($query);
        $query->shouldReceive('increment');

        $model->publicIncrement('foo', 1);
        $this->assertFalse($model->isDirty());

        $model->publicIncrement('foo', 1, ['category' => 1]);
        $this->assertEquals(4, $model->foo);
        $this->assertEquals(1, $model->category);
        $this->assertTrue($model->isDirty('category'));
    }

    public function testRelationshipTouchOwnersIsPropagated()
    {
        $relation = $this->getMockBuilder(BelongsTo::class)->setMethods(['touch'])->disableOriginalConstructor()->getMock();
        $relation->expects($this->once())->method('touch');

        $model = Mockery::mock(ModelStub::class.'[partner]');
        $this->addMockConnection($model);
        $model->shouldReceive('partner')->once()->andReturn($relation);
        $model->setTouchedRelations(['partner']);

        $mockPartnerModel = Mockery::mock(ModelStub::class.'[touchOwners]');
        $mockPartnerModel->shouldReceive('touchOwners')->once();
        $model->setRelation('partner', $mockPartnerModel);

        $model->touchOwners();
    }

    public function testRelationshipTouchOwnersIsNotPropagatedIfNoRelationshipResult()
    {
        $relation = $this->getMockBuilder(BelongsTo::class)->setMethods(['touch'])->disableOriginalConstructor()->getMock();
        $relation->expects($this->once())->method('touch');

        $model = Mockery::mock(ModelStub::class.'[partner]');
        $this->addMockConnection($model);
        $model->shouldReceive('partner')->once()->andReturn($relation);
        $model->setTouchedRelations(['partner']);

        $model->setRelation('partner', null);

        $model->touchOwners();
    }

    public function testModelAttributesAreCastedWhenPresentInCastsArray()
    {
        $model = new ModelCastingStub;
        $model->setDateFormat('Y-m-d H:i:s');
        $model->intAttribute = '3';
        $model->floatAttribute = '4.0';
        $model->stringAttribute = 2.5;
        $model->boolAttribute = 1;
        $model->booleanAttribute = 0;
        $model->objectAttribute = ['foo' => 'bar'];
        $obj = new stdClass;
        $obj->foo = 'bar';
        $model->arrayAttribute = $obj;
        $model->jsonAttribute = ['foo' => 'bar'];
        $model->dateAttribute = '1969-07-20';
        $model->datetimeAttribute = '1969-07-20 22:56:00';
        $model->timestampAttribute = '1969-07-20 22:56:00';

        $this->assertIsInt($model->intAttribute);
        $this->assertIsFloat($model->floatAttribute);
        $this->assertIsString($model->stringAttribute);
        $this->assertIsBool($model->boolAttribute);
        $this->assertIsBool($model->booleanAttribute);
        $this->assertIsObject($model->objectAttribute);
        $this->assertIsArray($model->arrayAttribute);
        $this->assertIsArray($model->jsonAttribute);
        $this->assertTrue($model->boolAttribute);
        $this->assertFalse($model->booleanAttribute);
        $this->assertEquals($obj, $model->objectAttribute);
        $this->assertEquals(['foo' => 'bar'], $model->arrayAttribute);
        $this->assertEquals(['foo' => 'bar'], $model->jsonAttribute);
        $this->assertEquals('{"foo":"bar"}', $model->jsonAttributeValue());
        $this->assertInstanceOf(Carbon::class, $model->dateAttribute);
        $this->assertInstanceOf(Carbon::class, $model->datetimeAttribute);
        $this->assertEquals('1969-07-20', $model->dateAttribute->toDateString());
        $this->assertEquals('1969-07-20 22:56:00', $model->datetimeAttribute->toDateTimeString());
        $this->assertEquals(-14173440, $model->timestampAttribute);

        $arr = $model->toArray();

        $this->assertIsInt($arr['intAttribute']);
        $this->assertIsFloat($arr['floatAttribute']);
        $this->assertIsString($arr['stringAttribute']);
        $this->assertIsBool($arr['boolAttribute']);
        $this->assertIsBool($arr['booleanAttribute']);
        $this->assertIsObject($arr['objectAttribute']);
        $this->assertIsArray($arr['arrayAttribute']);
        $this->assertIsArray($arr['jsonAttribute']);
        $this->assertTrue($arr['boolAttribute']);
        $this->assertFalse($arr['booleanAttribute']);
        $this->assertEquals($obj, $arr['objectAttribute']);
        $this->assertEquals(['foo' => 'bar'], $arr['arrayAttribute']);
        $this->assertEquals(['foo' => 'bar'], $arr['jsonAttribute']);
        $this->assertEquals('1969-07-20 00:00:00', $arr['dateAttribute']);
        $this->assertEquals('1969-07-20 22:56:00', $arr['datetimeAttribute']);
        $this->assertEquals(-14173440, $arr['timestampAttribute']);
    }

    public function testModelDateAttributeCastingResetsTime()
    {
        $model = new ModelCastingStub;
        $model->setDateFormat('Y-m-d H:i:s');
        $model->dateAttribute = '1969-07-20 22:56:00';

        $this->assertEquals('1969-07-20 00:00:00', $model->dateAttribute->toDateTimeString());

        $arr = $model->toArray();
        $this->assertEquals('1969-07-20 00:00:00', $arr['dateAttribute']);
    }

    public function testModelAttributeCastingPreservesNull()
    {
        $model = new ModelCastingStub;
        $model->intAttribute = null;
        $model->floatAttribute = null;
        $model->stringAttribute = null;
        $model->boolAttribute = null;
        $model->booleanAttribute = null;
        $model->objectAttribute = null;
        $model->arrayAttribute = null;
        $model->jsonAttribute = null;
        $model->dateAttribute = null;
        $model->datetimeAttribute = null;
        $model->timestampAttribute = null;

        $attributes = $model->getAttributes();

        $this->assertNull($attributes['intAttribute']);
        $this->assertNull($attributes['floatAttribute']);
        $this->assertNull($attributes['stringAttribute']);
        $this->assertNull($attributes['boolAttribute']);
        $this->assertNull($attributes['booleanAttribute']);
        $this->assertNull($attributes['objectAttribute']);
        $this->assertNull($attributes['arrayAttribute']);
        $this->assertNull($attributes['jsonAttribute']);
        $this->assertNull($attributes['dateAttribute']);
        $this->assertNull($attributes['datetimeAttribute']);
        $this->assertNull($attributes['timestampAttribute']);

        $this->assertNull($model->intAttribute);
        $this->assertNull($model->floatAttribute);
        $this->assertNull($model->stringAttribute);
        $this->assertNull($model->boolAttribute);
        $this->assertNull($model->booleanAttribute);
        $this->assertNull($model->objectAttribute);
        $this->assertNull($model->arrayAttribute);
        $this->assertNull($model->jsonAttribute);
        $this->assertNull($model->dateAttribute);
        $this->assertNull($model->datetimeAttribute);
        $this->assertNull($model->timestampAttribute);

        $array = $model->toArray();

        $this->assertNull($array['intAttribute']);
        $this->assertNull($array['floatAttribute']);
        $this->assertNull($array['stringAttribute']);
        $this->assertNull($array['boolAttribute']);
        $this->assertNull($array['booleanAttribute']);
        $this->assertNull($array['objectAttribute']);
        $this->assertNull($array['arrayAttribute']);
        $this->assertNull($array['jsonAttribute']);
        $this->assertNull($array['dateAttribute']);
        $this->assertNull($array['datetimeAttribute']);
        $this->assertNull($array['timestampAttribute']);
    }

    /**
     * @expectedException \Illuminate\Database\\JsonEncodingException
     * @expectedExceptionMessage Unable to encode attribute [objectAttribute] for model [Illuminate\Tests\Database\ModelCastingStub] to JSON: Malformed UTF-8 characters, possibly incorrectly encoded.
     */
    public function testModelAttributeCastingFailsOnUnencodableData()
    {
        $model = new ModelCastingStub;
        $model->objectAttribute = ['foo' => "b\xF8r"];
        $obj = new stdClass;
        $obj->foo = "b\xF8r";
        $model->arrayAttribute = $obj;
        $model->jsonAttribute = ['foo' => "b\xF8r"];

        $model->getAttributes();
    }

    public function testModelAttributeCastingWithSpecialFloatValues()
    {
        $model = new ModelCastingStub;

        $model->floatAttribute = 0;
        $this->assertSame(0.0, $model->floatAttribute);

        $model->floatAttribute = 'Infinity';
        $this->assertSame(INF, $model->floatAttribute);

        $model->floatAttribute = INF;
        $this->assertSame(INF, $model->floatAttribute);

        $model->floatAttribute = '-Infinity';
        $this->assertSame(-INF, $model->floatAttribute);

        $model->floatAttribute = -INF;
        $this->assertSame(-INF, $model->floatAttribute);

        $model->floatAttribute = 'NaN';
        $this->assertNan($model->floatAttribute);

        $model->floatAttribute = NAN;
        $this->assertNan($model->floatAttribute);
    }

    public function testUpdatingNonExistentModelFails()
    {
        $model = new ModelStub;
        $this->assertFalse($model->update());
    }

    public function testIssetBehavesCorrectlyWithAttributesAndRelationships()
    {
        $model = new ModelStub;
        $this->assertFalse(isset($model->nonexistent));

        $model->some_attribute = 'some_value';
        $this->assertTrue(isset($model->some_attribute));

        $model->setRelation('some_relation', 'some_value');
        $this->assertTrue(isset($model->some_relation));
    }

    public function testNonExistingAttributeWithInternalMethodNameDoesntCallMethod()
    {
        $model = Mockery::mock(ModelStub::class.'[delete,getRelationValue]');
        $model->name = 'Spark';
        $model->shouldNotReceive('delete');
        $model->shouldReceive('getRelationValue')->once()->with('belongsToStub')->andReturn('relation');

        // Can return a normal relation
        $this->assertEquals('relation', $model->belongsToStub);

        // Can return a normal attribute
        $this->assertEquals('Spark', $model->name);

        // Returns null for a Model.php method name
        $this->assertNull($model->delete);

        $model = Mockery::mock(ModelStub::class.'[delete]');
        $model->delete = 123;
        $this->assertEquals(123, $model->delete);
    }

    public function testIntKeyTypePreserved()
    {
        $model = $this->getMockBuilder(ModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with([], 'id')->andReturn(1);
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));

        $this->assertTrue($model->save());
        $this->assertEquals(1, $model->id);
    }

    public function testStringKeyTypePreserved()
    {
        $model = $this->getMockBuilder(KeyTypeModelStub::class)->setMethods(['newModelQuery', 'updateTimestamps', 'refresh'])->getMock();
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('insertGetId')->once()->with([], 'id')->andReturn('string id');
        $query->shouldReceive('getConnection')->once();
        $model->expects($this->once())->method('newModelQuery')->will($this->returnValue($query));

        $this->assertTrue($model->save());
        $this->assertEquals('string id', $model->id);
    }

    public function testScopesMethod()
    {
        $model = new ModelStub;
        $this->addMockConnection($model);

        $scopes = [
            'published',
            'category' => 'Laravel',
            'framework' => ['Laravel', '5.3'],
        ];

        $this->assertInstanceOf(Builder::class, $model->scopes($scopes));

        $this->assertSame($scopes, $model->scopesCalled);
    }

    public function testIsWithNull()
    {
        $firstInstance = new ModelStub(['id' => 1]);
        $secondInstance = null;

        $this->assertFalse($firstInstance->is($secondInstance));
    }

    public function testIsWithTheSameModelInstance()
    {
        $firstInstance = new ModelStub(['id' => 1]);
        $secondInstance = new ModelStub(['id' => 1]);
        $result = $firstInstance->is($secondInstance);
        $this->assertTrue($result);
    }

    public function testIsWithAnotherModelInstance()
    {
        $firstInstance = new ModelStub(['id' => 1]);
        $secondInstance = new ModelStub(['id' => 2]);
        $result = $firstInstance->is($secondInstance);
        $this->assertFalse($result);
    }

    public function testIsWithAnotherTable()
    {
        $firstInstance = new ModelStub(['id' => 1]);
        $secondInstance = new ModelStub(['id' => 1]);
        $secondInstance->setTable('foo');
        $result = $firstInstance->is($secondInstance);
        $this->assertFalse($result);
    }

    public function testIsWithAnotherConnection()
    {
        $firstInstance = new ModelStub(['id' => 1]);
        $secondInstance = new ModelStub(['id' => 1]);
        $secondInstance->setConnection('foo');
        $result = $firstInstance->is($secondInstance);
        $this->assertFalse($result);
    }

    public function testWithoutTouchingCallback()
    {
        $model = new ModelStub(['id' => 1]);

        $called = false;

        ModelStub::withoutTouching(function () use (&$called, $model) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testWithoutTouchingOnCallback()
    {
        $model = new ModelStub(['id' => 1]);

        $called = false;

        Model::withoutTouchingOn([ModelStub::class], function () use (&$called, $model) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    protected function addMockConnection($model)
    {
        Register::setConnectionResolver($resolver = Mockery::mock(ConnectionResolverInterface::class));
        $resolver->shouldReceive('connection')->andReturn(Mockery::mock(Connection::class));
        $model->getConnection()->shouldReceive('getQueryGrammar')->andReturn(Mockery::mock(Grammar::class));
        $model->getConnection()->shouldReceive('getPostProcessor')->andReturn(Mockery::mock(Processor::class));
    }
}