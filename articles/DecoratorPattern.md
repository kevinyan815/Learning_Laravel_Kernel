# 装饰模式 (Decorator Pattern)

装饰模式能够实现动态的为对象添加功能，是从一个对象外部来给对象添加功能。通常有两种方式可以实现给一个类或对象增加行为：

- 继承机制，使用继承机制是给现有类添加功能的一种有效途径，通过继承一个现有类可以使得子类在拥有自身方法的同时还拥有父类的方法。但是这种方法是静态的，用户不能控制增加行为的方式和时机。
- 组合机制，即将一个类的对象嵌入另一个对象中，由另一个对象来决定是否调用嵌入对象的行为以便扩展自己的行为，我们称这个嵌入的对象为装饰器(Decorator)

显然，为了扩展对象功能频繁修改父类或者派生子类这种方式并不可取。在面向对象的设计中，我们应该尽量使用对象组合，而不是对象继承来扩展和复用功能。装饰器模式就是基于对象组合的方式，可以很灵活的给对象添加所需要的功能。装饰器模式的本质就是动态组合。动态是手段，组合才是目的。总之，装饰模式是通过把复杂的功能简单化，分散化，然后在运行期间，根据需要来动态组合的这样一个模式。



### 装饰模式定义

装饰模式(Decorator Pattern) ：动态地给一个对象增加一些额外的职责(Responsibility)，就增加对象功能来说，装饰模式比生成子类实现更为灵活。其别名也可以称为包装器(Wrapper)，与适配器模式的别名相同，但它们适用于不同的场合。根据翻译的不同，装饰模式也有人称之为“油漆工模式”，它是一种对象结构型模式。

### 装饰模式的优点

- 装饰模式与继承关系的目的都是要扩展对象的功能，但是装饰模式可以提供比继承更多的灵活性。
- 可以通过一种动态的方式来扩展一个对象的功能，通过配置文件可以在运行时选择不同的装饰器，从而实现不同的行为。
- 通过使用不同的具体装饰类以及这些装饰类的排列组合，可以创造出很多不同行为的组合。可以使用多个具体装饰类来装饰同一对象，得到功能更为强大的对象。

### 模式结构和说明

![装饰器模式UML](https://user-gold-cdn.xitu.io/2018/6/3/163c449dbb766f7d)

- 聚合关系用一条带空心菱形箭头的直线表示，上图表示Component聚合到Decorator上，或者说Decorator由Component组成。
- 继承关系用一条带空心箭头的直接表示
- [看懂UML类图请看这个文档](http://design-patterns.readthedocs.io/zh_CN/latest/read_uml.html)

**Component**：组件对象的接口，可以给这些对象动态的添加职责；

**ConcreteComponent**：具体的组件对象，实现了组件接口。该对象通常就是被装饰器装饰的原始对象，可以给这个对象添加职责；

**Decorator**：所有装饰器的父类，需要定义一个与Component接口一致的接口(主要是为了实现装饰器功能的复用，即具体的装饰器A可以装饰另外一个具体的装饰器B，因为装饰器类也是一个Component)，并持有一个Component对象，该对象其实就是被装饰的对象。如果不继承Component接口类，则只能为某个组件添加单一的功能，即装饰器对象不能再装饰其他的装饰器对象。

**ConcreteDecorator**：具体的装饰器类，实现具体要向被装饰对象添加的功能。用来装饰具体的组件对象或者另外一个具体的装饰器对象。



### 装饰器的示例代码

1.Component抽象类, 可以给这些对象动态的添加职责

```
abstract class Component
{
	abstract public function operation();
}
```

2.Component的实现类

```
class ConcreteComponent extends Component
{
	public function operation()
	{
		echo __CLASS__ .  '|' . __METHOD__ . "\r\n";
	}
}
```

3.装饰器的抽象类，维持一个指向组件对象的接口对象， 并定义一个与组件接口一致的接口

```
abstract class Decorator extends Component
{
	/**
	 * 持有Component的对象
	 */
	protected $component;

	/**
	 * 构造方法传入
	 */
	public function __construct(Component $component)
	{
		$this->component = $component;
	}

	abstract public function operation();
}
```

4.装饰器的具体实现类，向组件对象添加职责，beforeOperation()，afterOperation()为前后添加的职责。

```
class ConcreteDecoratorA extends Decorator
{
	//在调用父类的operation方法的前置操作
	public function beforeOperation()
	{
		echo __CLASS__ . '|' . __METHOD__ . "\r\n";
	}

	//在调用父类的operation方法的后置操作
	public function afterOperation()
	{
		echo __CLASS__ . '|' . __METHOD__ . "\r\n";
	}

	public function operation()
	{
		$this->beforeOperation();
		$this->component->operation();//这里可以选择性的调用父类的方法，如果不调用则相当于完全改写了方法，实现了新的功能
		$this->afterOperation();
	}
}

class ConcreteDecoratorB extends Decorator
{
	//在调用父类的operation方法的前置操作
	public function beforeOperation()
	{
		echo __CLASS__ . '|' . __METHOD__ . "\r\n";
	}

	//在调用父类的operation方法的后置操作
	public function afterOperation()
	{
		echo __CLASS__ . '|' . __METHOD__ . "\r\n";
	}

	public function operation()
	{
		$this->beforeOperation();
		$this->component->operation();//这里可以选择性的调用父类的方法，如果不调用则相当于完全改写了方法，实现了新的功能
		$this->afterOperation();
	}
}
```

5.客户端使用装饰器

```
class Client
{
	public function main()
	{
		$component = new ConcreteComponent();
		$decoratorA = new ConcreteDecoratorA($component);
		$decoratorB = new ConcreteDecoratorB($decoratorA);
		$decoratorB->operation();
	}
}

$client = new Client();
$client->main();
```

6.运行结果

```
oncreteDecoratorB|ConcreteDecoratorB::beforeOperation
ConcreteDecoratorA|ConcreteDecoratorA::beforeOperation
ConcreteComponent|ConcreteComponent::operation
ConcreteDecoratorA|ConcreteDecoratorA::afterOperation
ConcreteDecoratorB|ConcreteDecoratorB::afterOperation
```



### 装饰模式需要注意的问题

- 一个装饰类的接口必须与被装饰类的接口保持相同，对于客户端来说无论是装饰之前的对象还是装饰之后的对象都可以一致对待。
- 尽量保持具体组件类ConcreteComponent的轻量，不要把主逻辑之外的辅助逻辑和状态放在具体组件类中，可以通过装饰类对其进行扩展。 如果只有一个具体组件类而没有抽象组件类，那么抽象装饰类可以作为具体组件类的直接子类。



### 适用环境

- 需要在不影响组件对象的情况下，以动态、透明的方式给对象添加职责。
- 当不能采用继承的方式对系统进行扩充或者采用继承不利于系统扩展和维护时可以考虑使用装饰类。

上一篇: [路由](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Route.md)

下一篇: [中间件](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Middleware.md)
