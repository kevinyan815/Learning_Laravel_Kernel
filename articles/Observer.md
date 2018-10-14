# 观察者模式

Laravel的Event事件系统提供了一个简单的观察者模式实现，能够订阅和监听应用中发生的各种事件，在PHP的标准库(SPL)里甚至提供了三个接口`SplSubject`, `SplObserver`, `SplObjectStorage`来让开发者更容易地实现观察者模式，不过我还是想脱离SPL提供的接口和特定编程语言来说一下如何通过面向对象程序设计来实现观察者模式，示例是PHP代码不过用其他面向对象语言实现起来也是一样的。

### 模式定义

观察者模式(Observer Pattern)：定义对象间的一种一对多依赖关系，使得每当一个对象状态发生改变时，其相关依赖对象皆得到通知并被自动更新。观察者模式又叫做发布-订阅（Publish/Subscribe）模式、模型-视图（Model/View）模式、源-监听器（Source/Listener）模式或从属者（Dependents）模式。

观察者模式的核心在于Subject和Observer接口，Subject(主题目标)包含一个给定的状态，观察者“订阅”这个主题，将主题的当前状态通知观察者，每次给定状态改变时所有观察者都会得到通知。

发生改变的对象称为观察目标，而被通知的对象称为观察者，一个观察目标可以对应多个观察者，而且这些观察者之间没有相互联系，可以根据需要增加和删除观察者，使得系统更易于扩展。



### 模式结构说明

![观察者模式UML](https://user-gold-cdn.xitu.io/2018/6/6/163d27e2a360d07e?imageslim)

- Subject  目标抽象类
- ConcreteSubject 具体目标
- Observer 观察者抽象类
- ConcreteObserver 具体观察者



### 应用举例

比如在设置用户(主题)的状态后要分别发送当前的状态描述信息给用户的邮箱和手机，我们可以使用两个观察者订阅用户的状态，一旦设置状态后主题就会通知的订阅了状态改变的观察者，在两个观察者里面我们可以分别来实现发送邮件信息和短信信息的功能。

1. 抽象目标类

```
abstract class Subject
{
    protected $stateNow;
    protected $observers = [];

    public function attach(Observer $observer)
    {
        array_push($this->observers, $observer);
    }

    public function detach(Observer $ob)
    {
        $pos = 0;
        foreach ($this->observers as $viewer) {
            if ($viewer == $ob) {
                array_splice($this->observers, $pos, 1);
            }
            $pos++;
        }
    }

    public function notify()
    {
        foreach ($this->observers as $viewer) {
            $viewer->update($this);
        }
    }
}
```

在抽象类中`attach` `detach` 和`notify`都是具体方法，这些是继承才能使用的方法，将由`Subject`的子类使用。

2. 具体目标类

```
class ConcreteSubject extends Subject
{
    public function setState($state) 
    {
        $this->stateNow = $state;
        $this->notify();
    }

    public function getState()
    {
        return $this->stateNow;
    }
}
```

3. 抽象观察者

```
abstract class Observer
{
    abstract public function update(Subject $subject);
}
```

在抽象观察者中，抽象方法`update`等待子类为它提供一个特定的实现。

4. 具体观察者

```
class ConcreteObserverDT extends Observer
{
    private $currentState;

    public function update(Subject $subject)
    {
        $this->currentState = $subject->getState();

        echo '<div style="font-size:10px;">'. $this->currentState .'</div>';
    }
}

class ConcreteObserverPhone extends Observer
{
    private $currentState;

    public function update(Subject $subject)
    {
        $this->currentState = $subject->getState();

        echo '<div style="font-size:20px;">'. $this->currentState .'</div>';
    }
}
```

在例子中为了理解起来简单，我们只是根据不同的客户端设置了不同的内容样式，实际应用中可以真正的调用邮件和短信服务来发送信息。

5. 使用观察者模式

```
class Client 
{
    public function __construct()
    {
        $sub = new ConcreteSubject();

        $obDT = new ConcreteObserverDT();
        $obPhone = new ConcreteObserverPhone();

        $sub->attach($obDT);
        $sub->attach($obPhone);
        $sub->setState('Hello World');
    }
}

$worker = new Client();
```



### 何时使用观察者模式

- 一个对象的改变将导致其他一个或多个对象也发生改变，而不知道具体有多少对象将发生改变，可以降低对象之间的耦合度。
- 一个对象必须通知其他对象，而并不知道这些对象是谁。
- 基于事件触发机制来解耦复杂逻辑时，从整个逻辑的不同关键点抽象出不同的事件，主流程只需要关心最核心的逻辑并能正确地触发事件(Subject)，其余相关功能实现由观察者或者叫订阅者来完成。



### 总结

- 观察者模式定义了一种一对多的依赖关系，让多个观察者对象同时监听某一个目标对象，当这个目标对象的状态发生变化时，会通知所有观察者对象，使它们能够自动更新。
- 模式包含四个角色：目标又称为主题，它是指被观察的对象；具体目标是目标类的子类，通常它包含有经常发生改变的数据，当它的状态发生改变时，向它的各个观察者发出通知；观察者将对观察目标的改变做出反应；在具体观察者中维护一个指向具体目标对象的引用，它存储具体观察者的有关状态，这些状态需要和具体目标的状态保持一致。

- 观察者模式的主要优点在于可以实现表示层和数据逻辑层的分离，并在观察目标和观察者之间建立一个抽象的耦合，支持广播通信；其主要缺点在于如果一个观察目标对象有很多直接和间接的观察者的话，将所有的观察者都通知到会花费很多时间，而且如果在观察者和观察目标之间有循环依赖的话，观察目标会触发它们之间进行循环调用，可能导致系统崩溃。

上一篇: [Database 模型关联](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database4.md)

下一篇: [事件系统](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Event.md)
