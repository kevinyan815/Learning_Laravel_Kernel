# 类的反射和依赖注入

在讲服务容器之前我想先梳理下PHP反射相关的知识，PHP反射是程序实现依赖注入的基础，也是Laravel的服务容器实现服务解析的基础，如果你已经掌握了这方面基础知识，那么可以跳过本文直接看服务容器部分的内容。

PHP具有完整的反射 API，提供了对类、接口、函数、方法和扩展进行逆向工程的能力。通过类的反射提供的能力我们能够知道类是如何被定义的，它有什么属性、什么方法、方法都有哪些参数，类文件的路径是什么等很重要的信息。也正式因为类的反射很多PHP框架才能实现依赖注入自动解决类与类之间的依赖关系，这给我们平时的开发带来了很大的方便。 本文主要是讲解如何利用类的反射来实现依赖注入(Dependency Injection)，并不会去逐条讲述PHP Reflection里的每一个API，详细的API参考信息请查阅[官方文档][1]

**再次声明这里实现的依赖注入非常简单，并不能应用到实际开发中去，可以参考后面的文章[服务容器(IocContainer)][2], 了解Laravel的服务容器是如何实现依赖注入的。**

为了更好地理解，我们通过一个例子来看类的反射，以及如何实现依赖注入。
下面这个类代表了坐标系里的一个点，有两个属性横坐标x和纵坐标y。
```php
/**
 * Class Point
 */
class Point
{
    public $x;
    public $y;

    /**
     * Point constructor.
     * @param int $x  horizontal value of point's coordinate
     * @param int $y  vertical value of point's coordinate
     */
    public function __construct($x = 0, $y = 0)
    {
        $this->x = $x;
        $this->y = $y;
    }
}
```
接下来这个类代表圆形，可以看到在它的构造函数里有一个参数是`Point`类的，即`Circle`类是依赖与`Point`类的。

```php
class Circle
{
    /**
     * @var int
     */
    public $radius;//半径

    /**
     * @var Point
     */
    public $center;//圆心点

    const PI = 3.14;

    public function __construct(Point $point, $radius = 1)
    {
        $this->center = $point;
        $this->radius = $radius;
    }
    
    //打印圆点的坐标
    public function printCenter()
    {
        printf('center coordinate is (%d, %d)', $this->center->x, $this->center->y);
    }

    //计算圆形的面积
    public function area()
    {
        return 3.14 * pow($this->radius, 2);
    }
}
```
ReflectionClass
--

下面我们通过反射来对`Circle`这个类进行反向工程。
把`Circle`类的名字传递给`reflectionClass`来实例化一个`ReflectionClass`类的对象。

```php
$reflectionClass = new reflectionClass(Circle::class);
//返回值如下
object(ReflectionClass)#1 (1) {
  ["name"]=>
  string(6) "Circle"
}
```
反射出类的常量
--
```php
$reflectionClass->getConstants();
```
返回一个由常量名称和值构成的关联数组
```php
array(1) {
  ["PI"]=>
  float(3.14)
}
```

通过反射获取属性
--
```php
$reflectionClass->getProperties();
```
返回一个由ReflectionProperty对象构成的数组
```php
array(2) {
  [0]=>
  object(ReflectionProperty)#2 (2) {
    ["name"]=>
    string(6) "radius"
    ["class"]=>
    string(6) "Circle"
  }
  [1]=>
  object(ReflectionProperty)#3 (2) {
    ["name"]=>
    string(6) "center"
    ["class"]=>
    string(6) "Circle"
  }
}
```
反射出类中定义的方法
--
```php
$reflectionClass->getMethods();
```
返回ReflectionMethod对象构成的数组
```php
array(3) {
  [0]=>
  object(ReflectionMethod)#2 (2) {
    ["name"]=>
    string(11) "__construct"
    ["class"]=>
    string(6) "Circle"
  }
  [1]=>
  object(ReflectionMethod)#3 (2) {
    ["name"]=>
    string(11) "printCenter"
    ["class"]=>
    string(6) "Circle"
  }
  [2]=>
  object(ReflectionMethod)#4 (2) {
    ["name"]=>
    string(4) "area"
    ["class"]=>
    string(6) "Circle"
  }
}
```
我们还可以通过`getConstructor()`来单独获取类的构造方法，其返回值为一个`ReflectionMethod`对象。
```php
$constructor = $reflectionClass->getConstructor();
```
反射出方法的参数
--
```php
$parameters = $constructor->getParameters();
```
其返回值为ReflectionParameter对象构成的数组。
```php
array(2) {
  [0]=>
  object(ReflectionParameter)#3 (1) {
    ["name"]=>
    string(5) "point"
  }
  [1]=>
  object(ReflectionParameter)#4 (1) {
    ["name"]=>
    string(6) "radius"
  }
}
```

依赖注入
--
好了接下来我们编写一个名为`make`的函数，传递类名称给`make`函数返回类的对象，在`make`里它会帮我们注入类的依赖，即在本例中帮我们注入`Point`对象给`Circle`类的构造方法。
```php
//构建类的对象
function make($className)
{
    $reflectionClass = new ReflectionClass($className);
    $constructor = $reflectionClass->getConstructor();
    $parameters  = $constructor->getParameters();
    $dependencies = getDependencies($parameters);
    
    return $reflectionClass->newInstanceArgs($dependencies);
}

//依赖解析
function getDependencies($parameters)
{
    $dependencies = [];
    foreach($parameters as $parameter) {
        $dependency = $parameter->getClass();
        if (is_null($dependency)) {
            if($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } else {
                //不是可选参数的为了简单直接赋值为字符串0
                //针对构造方法的必须参数这个情况
                //laravel是通过service provider注册closure到IocContainer,
                //在closure里可以通过return new Class($param1, $param2)来返回类的实例
                //然后在make时回调这个closure即可解析出对象
                //具体细节我会在另一篇文章里面描述
                $dependencies[] = '0';
            }
        } else {
            //递归解析出依赖类的对象
            $dependencies[] = make($parameter->getClass()->name);
        }
    }

    return $dependencies;
}
```
定义好`make`方法后我们通过它来帮我们实例化Circle类的对象:
```php
$circle = make('Circle');
$area = $circle->area();
/*var_dump($circle, $area);
object(Circle)#6 (2) {
  ["radius"]=>
  int(1)
  ["center"]=>
  object(Point)#11 (2) {
    ["x"]=>
    int(0)
    ["y"]=>
    int(0)
  }
}
float(3.14)*/
```

通过上面这个实例我简单描述了一下如何利用PHP类的反射来实现依赖注入，Laravel的依赖注入也是通过这个思路来实现的，只不过设计的更精密大量地利用了闭包回调来应对各种复杂的依赖注入。

本文的[示例代码的下载链接][4]


下一篇：[Laravel服务容器][3]

  [1]: http://php.net/manual/zh/intro.reflection.php
  [2]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md
  [3]: https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md
  [4]: https://github.com/kevinyan815/php_reflection_dependency_injection_demo/blob/master/reflection.php
