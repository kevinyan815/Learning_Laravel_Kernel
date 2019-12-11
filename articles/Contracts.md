# Contracts

Laravel 的契约是一组定义框架提供的核心服务的接口， 例如我们在介绍用户认证的章节中到的用户看守器契约[Illumninate\Contracts\Auth\Guard](https://github.com/illuminate/contracts/blob/master/Auth/Guard.php) 和用户提供器契约[Illuminate\Contracts\Auth\UserProvider](https://github.com/illuminate/contracts/blob/master/Auth/UserProvider.php)

以及框架自带的`App\User`模型所实现的[Illuminate\Contracts\Auth\Authenticatable](https://github.com/illuminate/contracts/blob/master/Auth/Authenticatable.php)契约。

### 为什么使用契约

通过上面几个契约的源码文件我们可以看到，Laravel提供的契约是为核心模块定义的一组interface。Laravel为每个契约都提供了相应的实现类，下表列出了Laravel为上面提到的三个契约提供的实现类。

| 契约                                                         | Laravel内核提供的实现类                                      |
| ------------------------------------------------------------ | ------------------------------------------------------------ |
| [Illumninate\Contracts\Auth\Guard](https://github.com/illuminate/contracts/blob/master/Auth/Guard.php) | [Illuminate\Auth\SessionGuard](https://github.com/illuminate/auth/blob/master/SessionGuard.php) |
| [Illuminate\Contracts\Auth\UserProvider](https://github.com/illuminate/contracts/blob/master/Auth/UserProvider.php) | [Illuminate\Auth\EloquentUserProvider](https://github.com/illuminate/auth/blob/master/EloquentUserProvider.php) |
| [Illuminate\Contracts\Auth\Authenticatable](https://github.com/illuminate/contracts/blob/master/Auth/Authenticatable.php) | Illuminate\Foundation\Auth\Authenticatable(User Model的父类) |

所以在自己开发的项目中，如果Laravel提供的用户认证系统无法满足需求，你可以根据需求定义看守器和用户提供器的实现类，比如我之前做的项目就是用户认证依赖于公司的员工管理系统的API，所以我就自己写了看守器和用户提供器契约的实现类，让Laravel通过自定义的Guard和UserProvider来完成用户认证。自定义用户认证的方法在介绍用户认证的章节中我们介绍过，读者可以去翻阅那块的文章。



所以Laravel为所有的核心功能都定义契约接口的目的就是为了让开发者能够根据自己项目的需要自己定义实现类，而对于这些接口的消费者(比如：Controller、或者内核提供的 AuthManager这些)他们不需要关心接口提供的方法具体是怎么实现的， 只关心接口的方法能提供什么功能然后去使用这些功能就可以了，我们可以根据需求在必要的时候为接口更换实现类，而消费端不用进行任何改动。

### 定义和使用契约

上面我们提到的都是Laravel内核提供的契约， 在开发大型项目的时候我们也可以自己在项目中定义契约和实现类，你有可能会觉得自带的Controller、Model两层就已经足够你编写代码了，凭空多出来契约和实现类会让开发变得繁琐。我们先从一个简单的例子出发，考虑下面的代码有什么问题：

```
class OrderController extends Controller
{
    public function getUserOrders()
    {
        $orders= Order::where('user_id', '=', \Auth::user()->id)->get();
        return View::make('order.index', compact('orders'));
    }
}
```



这段代码很简单，但我们要想测试这段代码的话就一定会和实际的数据库发生联系。也就是说，  ORM和这个控制器有着紧耦合。如果不使用Eloquent ORM，不连接到实际数据库，我们就没办法运行或者测试这段代码。这段代码同时也违背了“关注分离”这个软件设计原则。简单讲：这个控制器知道的太多了。 控制器不需要去了解数据是从哪儿来的，只要知道如何访问就行。控制器也不需要知道这数据是从MySQL或哪儿来的，只需要知道这数据目前是可用的。

>**Separation Of Concerns    关注分离**
>
>Every class should have a single responsibility, and that responsibility should be entirely encapsulated by the class.
>
>每个类都应该只有单一的职责，并且职责里所有的东西都应该由这个类封装

接下来我们定义一个接口，然后实现该接口

```
interface OrderRepositoryInterface 
{
    public function userOrders(User $user);
}

class OrderRepository implements OrderRepositoryInterface
{
    public function userOrders(User $user)
    {
		Order::where('user_id', '=', $user->id)->get();
    }
}
```

将接口的实现绑定到Laravel的服务容器中

```

App::singleton('OrderRepositoryInterface', 'OrderRespository');
```



然后我们将该接口的实现注入我们的控制器

```
class UserController extends Controller
{
    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->orders = $orderRespository;
    }
  
    public function getUserOrders(User $user)
    {
        $orders = $this->orders->userOrders($user);
        return View::make('order.index', compact('orders'));
    }
}
```

现在我们的控制器就完全和数据层面无关了。在这里我们的数据可能来自MySQL，MongoDB或者Redis。我们的控制器不知道也不需要知道他们的区别。这样我们就可以独立于数据层来测试Web层了，将来切换存储实现也会很容易。

### 接口与团队开发

当你的团队在开发大型应用时，不同的部分有着不同的开发速度。比如一个开发人员在开发数据层，另一个开发人员在做控制器层。写控制器的开发者想测试他的控制器，不过数据层开发较慢没法同步测试。那如果两个开发者能先以interface的方式达成协议，后台开发的各种类都遵循这种协议。一旦建立了约定，就算约定还没实现，开发者也可以为这接口写个“假”实现

```
class DummyOrderRepository implements OrderRepositoryInterface 
{
    public function userOrders(User $user)
    {
        return collect(['Order 1', 'Order 2', 'Order 3']);
    }
}
```

一旦假实现写好了，就可以被绑定到IoC容器里

```
App::singleton('OrderRepositoryInterface', 'DummyOrderRepository');
```

然后这个应用的视图就可以用假数据填充了。接下来一旦后台开发者写完了真正的实现代码，比如叫`RedisOrderRepository`。那么使用IoC容器切换接口实现，应用就可以轻易地切换到真正的实现上，整个应用就会使用从Redis读出来的数据了。

### 接口与测试

建立好接口约定后也更有利于我们在测试时进行Mock

```
public function testIndexActionBindsUsersFromRepository()
{    
    // Arrange...
    $repository = Mockery::mock('OrderRepositoryInterface');
    $repository->shouldReceive('userOrders')->once()->andReturn(['order1', 'order2']);
    App::instance('OrderRepositoryInterface', $repository);
    // Act...
    $response  = $this->action('GET', 'OrderController@getUserOrders');
        
    // Assert...
    $this->assertResponseOk();
    $this->assertViewHas('order', ['order1', 'order2']);
 }
```



### 总结

接口在程序设计阶段非常有用，在设计阶段与团队讨论完成功能需要制定哪些接口，然后设计出每个接口具体要实现的方法，方法的入参和返回值这些，每个人就可以按照接口的约定来开发自己的模块，遇到还没实现的接口完全可以先定义接口的假实现等到真正的实现开发完成后再进行切换，这样既降低了软件程序结构中上层对下层的耦合也能保证各部分的开发进度不会过度依赖其他部分的完成情况。

上一篇: [Cookie源码解析](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Cookie.md)

下一篇: [ENV配置的加载和读取](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ENV.md)


