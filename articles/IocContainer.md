# 服务容器(IocContainer)

Laravel的核心是IocContainer, 文档中称其为“服务容器”，服务容器是一个用于管理类依赖和执行依赖注入的强大工具，Laravel中的功能模块比如 Route、Eloquent ORM、Request、Response等等等等，实际上都是与核心无关的类模块提供的，这些类从注册到实例化，最终被我们所使用，其实都是 laravel 的服务容器负责的。

如果对服务容器是什么没有清晰概念的话推荐一篇博文来了解一下服务容器的来龙去脉：[laravel神奇的服务容器](https://www.insp.top/learn-laravel-container)

服务容器中有两个概念[控制反转(IOC)和依赖注入(DI)](http://blog.csdn.net/doris_crazy/article/details/18353197):

>依赖注入和控制反转是对同一件事情的不同描述，它们描述的角度不同。依赖注入是从应用程序的角度在描述，应用程序依赖容器创建并注入它所需要的外部资源。而控制反转是从容器的角度在描述，容器控制应用程序，由容器反向的向应用程序注入应用程序所需要的外部资源。

在Laravel中框架把自带的各种服务绑定到服务容器，我们也可以绑定自定义服务到容器。当应用程序需要使用某一个服务时，服务容器会讲服务解析出来同时自动解决服务之间的依赖然后交给应用程序使用。

本篇就来探讨一下Laravel中的服务绑定和解析是如何实现的

## 服务绑定
常用的绑定服务到容器的方法有instance, bind, singleton, alias。下面我们分别来看一下。

### instance
将一个已存在的对象绑定到服务容器里，随后通过名称解析该服务时，容器将总返回这个绑定的实例。

    $api = new HelpSpot\API(new HttpClient);
    $this->app->instance('HelpSpot\Api', $api);
    
会把对象注册到服务容器的$instances属性里
  
    ［
         'HelpSpot\Api' => $api//$api是API类的对象，这里简写了
     ］
     
### bind
绑定服务到服务容器

有三种绑定方式:

    1.绑定自身
    $this->app->bind('HelpSpot\API', null);
    
    2.绑定闭包
    $this->app->bind('HelpSpot\API', function () {
    	return new HelpSpot\API();
	});//闭包直接提供类实现方式
    $this->app->bind('HelpSpot\API', function ($app) {
    	return new HelpSpot\API($app->make('HttpClient'));
	});//闭包返回需要依赖注入的类
	3. 绑定接口和实现
    $this->app->bind('Illuminate\Tests\Container\IContainerContractStub', 'Illuminate\Tests\Container\ContainerImplementationStub');
    
	
针对第一种情况，其实在bind方法内部会在绑定服务之前通过`getClosure()`为服务生成闭包，我们来看一下bind方法源码。

    public function bind($abstract, $concrete = null, $shared = false)
    {
        $abstract = $this->normalize($abstract);
        
        $concrete = $this->normalize($concrete);
        //如果$abstract为数组类似['Illuminate/ServiceName' => 'service_alias']
        //抽取别名"service_alias"并且注册到$aliases[]中
        //注意：数组绑定别名的方式在5.4中被移除，别名绑定请使用下面的alias方法
        if (is_array($abstract)) {
            list($abstract, $alias) = $this->extractAlias($abstract);

            $this->alias($abstract, $alias);
        }

        $this->dropStaleInstances($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        //如果只提供$abstract，则在这里为其生成concrete闭包
        if (! $concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');

        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }
    
    
    protected function getClosure($abstract, $concrete)
    {
        // $c 就是$container，即服务容器，会在回调时传递给这个变量
        return function ($c, $parameters = []) use ($abstract, $concrete) {
            $method = ($abstract == $concrete) ? 'build' : 'make';

            return $c->$method($concrete, $parameters);
        };
    }
	
	
	
	
bind把服务注册到服务容器的$bindings属性里类似这样：

	$bindings = [
		'HelpSpot\API' =>  [//闭包绑定
			'concrete' => function ($app, $paramters = []) {
				return $app->build('HelpSpot\API');
			},
			'shared' => false//如果是singleton绑定，这个值为true
		]		
		'Illuminate\Tests\Container\IContainerContractStub' => [//接口实现绑定
			'concrete' => 'Illuminate\Tests\Container\ContainerImplementationStub',
			'shared' => false
		]
	]
	

### singleton

    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }
    
singleton 方法是bind方法的变种，绑定一个只需要解析一次的类或接口到容器，然后接下来对于容器的调用该服务将会返回同一个实例

### alias
 把服务和服务别名注册到容器:

    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $this->normalize($abstract);
    }
alias 方法在上面讲bind方法里有用到过，它会把把服务别名和服务类的对应关系注册到服务容器的$aliases属性里。
例如:
    $this->app->alias('\Illuminate\ServiceName', 'service_alias');    
绑定完服务后在使用时就可以通过
    $this->app->make('service_alias');
将服务对象解析出来，这样make的时候就不用写那些比较长的类名称了，对make方法的使用体验上有很大提升。


## 服务解析
make: 从服务容器中解析出服务对象，该方法接收你想要解析的类名或接口名作为参数

    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array   $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        // getAlias方法会假定$abstract是绑定的别名，从$aliases找到映射的真实类型名
        // 如果没有映射则$abstract即为真实类型名，将$abstract原样返回
        $abstract = $this->getAlias($this->normalize($abstract));

        // 如果服务是通过instance()方式绑定的，就直接解析返回绑定的service
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // 获取$abstract接口对应的$concrete(接口的实现)
        $concrete = $this->getConcrete($abstract);

        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete, $parameters);
        } else {
            // 如果时接口实现这种绑定方式，通过接口拿到实现后需要再make一次才能
            // 满足isBuildable的条件 ($abstract === $concrete)
            $object = $this->make($concrete, $parameters);
        }

        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // 如果服务是以singleton方式注册进来的则，把构建好的服务对象放到$instances里，
        // 避免下次使用时重新构建
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        $this->fireResolvingCallbacks($abstract, $object);

        $this->resolved[$abstract] = true;

        return $object;
    }
    
    protected function getConcrete($abstract)
    {
        if (! is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }

        // 如果是$abstract之前没有注册类实现到服务容器里，则服务容器会认为$abstract本身就是接口的类实现
        if (! isset($this->bindings[$abstract])) {
            return $abstract;
        }

        return $this->bindings[$abstract]['concrete'];
    }
    
    protected function isBuildable($concrete, $abstract)
    {        
        return $concrete === $abstract || $concrete instanceof Closure;
    }
   
 
 
 通过对make方法的梳理我们发现，build方法的职能是构建解析出来的服务的对象的，下面看一下构建对象的具体流程。（构建过程中用到了[PHP类的反射][1]来实现服务的依赖注入）
 

    public function build($concrete, array $parameters = [])
    {
        // 如果是闭包直接执行闭包并返回（对应闭包绑定）
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }
        
        // 使用反射ReflectionClass来对实现类进行反向工程
        $reflector = new ReflectionClass($concrete);

        // 如果不能实例化，这应该是接口或抽象类，再或者就是构造函数是private的
        if (! $reflector->isInstantiable()) {
            if (! empty($this->buildStack)) {
                $previous = implode(', ', $this->buildStack);

                $message = "Target [$concrete] is not instantiable while building [$previous].";
            } else {
                $message = "Target [$concrete] is not instantiable.";
            }

            throw new BindingResolutionException($message);
        }

        $this->buildStack[] = $concrete;

        // 获取构造函数
        $constructor = $reflector->getConstructor();

        // 如果构造函数是空，说明没有任何依赖，直接new返回
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }
        
        // 获取构造函数的依赖（形参），返回一组ReflectionParameter对象组成的数组表示每一个参数
        $dependencies = $constructor->getParameters();

        $parameters = $this->keyParametersByArgument(
            $dependencies, $parameters
        );

        // 构建构造函数需要的依赖
        $instances = $this->getDependencies(
            $dependencies, $parameters
        );

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instances);
    }
    
    // 获取依赖
    protected function getDependencies(array $parameters, array $primitives = [])
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getClass();

            // 某一依赖值在$primitives中(如：app()->make(ApiService::class, ['clientId' => 'id'])调用时$primitives里会包含ApiService类构造方法中参数$clientId的参数值)已提供
            // $parameter->name返回参数名
            if (array_key_exists($parameter->name, $primitives)) {
                $dependencies[] = $primitives[$parameter->name];
            } 
            elseif (is_null($dependency)) {
                 // 参数的ReflectionClass为null，说明是基本类型，如'int','string'
                $dependencies[] = $this->resolveNonClass($parameter);
            } else {
            	 // 参数是一个类的对象， 则用resolveClass去把对象解析出来
                $dependencies[] = $this->resolveClass($parameter);
            }
        }

        return $dependencies;
    }
    
    // 解析出依赖类的对象
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            // $parameter->getClass()->name返回的是类名（参数在typehint里声明的类型）
            // 然后递归继续make（在make时发现依赖类还有其他依赖，那么会继续make依赖的依赖
            // 直到所有依赖都被解决了build才结束)
            return $this->make($parameter->getClass()->name);
        } catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }
    
    
   服务容器就是laravel的核心， 它通过依赖注入很好的替我们解决对象之间的相互依赖关系，而又通过控制反转让外部来来定义具体的行为（Route, Eloquent这些都是外部模块，它们自己定义了行为规范，这些类从注册到实例化给你使用才是服务容器负责的）。
   
   一个类要被容器所能够提取，必须要先注册至这个容器。既然 laravel 称这个容器叫做服务容器，那么我们需要某个服务，就得先注册、绑定这个服务到容器，那么提供服务并绑定服务至容器的东西就是服务提供器（ServiceProvider)。服务提供者主要分为两个部分：register（注册） 和 boot（引导、初始化）这就引出了我们后面要学习的内容。

上一篇: [类的反射和依赖注入][1]

下一篇: [服务提供器][3]


  [1]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/reflection.md
  [3]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ServiceProvider.md
