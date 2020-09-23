# Learning_Laravel_Kernel

Laravel核心代码学习

## 前言

如果你对`Laravel`里面的依赖注入、服务绑定、服务解析等等这些东西很好奇，并且觉得只有理解了一个框架的核心代码才能真正把一个框架用好才能写出最佳实践，那么这个教程对你会很有帮助。教程完整覆盖了`Laravel`核心的所有内容，并且根据开发者使用`Laravel`的通用场景开始逐步深入内核讲解整个框架核心流程中涉及到的方方面面，整个教程的目录顺序也是根据用`Laravel`进行开发时常涉及的部分编排的。相信你认真学完这个教程自己融汇贯通后就能完全掌握`Laravel`并胜任用它设计和架构生产系统的职责。
## 面向的人群

要想很好地理解文章的内容你需要具备一定的`PHP`基础和`Laravel`的知识，我并不会解释核心里的每一行代码，更多的是通过梳理代码流程来解释`Laravel`核心模块里最典型功能的设计思路和具体实现。所以我希望读者可以将文章内容看作是源代码的导读，跟随文章自己逐步地去看一遍`Laravel`每个核心组件的代码，如果遇到理解起来比较困难的地方就去补齐那里用到的知识再来继续阅读，我也希望读者在理解了文章里说的那些典型功能后能够自己再去举一三地看看模块里其他功能的源代码。相信看完`Laravel`核心的代码后你不仅能更熟练地使用`Laravel`也能在其它基础知识方面有所提高。

## 涉及的内容

文章主要专注于`Laravel`核心的学习，包括:服务容器、服务提供器、中间件、路由、`Facades`、事件驱动系统、Auth用户认证系统以及作为核心服务的`Database`、`Request`、`Response`、`Cookie`和`Session`。`Laravel`里其它的部分也都是作为服务注册到服务容器里提供给应用使用的，当你理解了上面那些东西后再去看其它的服务也就会很容易理解了。在学习源码的过程中我会向读者解释关于这些核心模块的常见问题比如：使用`DB`或者`Model`操作数据库时`Laravel`是什么时候连接上数据库的？ 注册到容器的服务是怎么被解析出来的等等。

## 关于框架版本
在通过这个项目学习`Laravel`核心代码时请使用`Laravel5.5`版本，由于服务容器和中间件两篇文章成稿比较早那会还在使用5.2版本的Laravel做项目所以引用的代码也来自5.2版本，其余章节的代码均引用自`Laravel5.5`的核心，两个版本的核心代码差异很小我已经在这两篇文章中标注出差异的地方所以不影响读者使用这个项目来学习`Laravel5.5`版本的核心代码。

## Contact
- [Open an issue](https://github.com/kevinyan815/Learning_Laravel_Kernel/issues)
- Gmail: kevinyan815@gmail.com
- 公众号: "网管叨bi叨"

![](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/images/WX20200119-143845%402x.png)

## 文章目录

- [类的反射和依赖注入](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/reflection.md)
- [服务容器(IocContainer)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/IocContainer.md)
- [服务提供器(ServiceProvider)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ServiceProvider.md)
- [外观模式(Facade Pattern)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/FacadePattern.md)
- [Facades](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Facades.md)
- [路由](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Route.md)
- [装饰模式(Decorator Pattern)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/DecoratorPattern.md)
- [中间件](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Middleware.md)
- [控制器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Controller.md)
- [Request](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Request.md)
- [Response](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Response.md)
- [Database 基础介绍](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database1.md)
- [Database 查询构建器](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database2.md)
- [Database 模型CRUD](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database3.md)
- [Database 模型关联](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Database4.md)
- [观察者模式](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Observer.md)
- [事件系统](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Event.md)
- [用户认证系统(基础介绍)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth1.md)
- [用户认证系统(实现细节)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth2.md)
- [扩展用户认证系统](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth3.md)
- [Session源码解析](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Session.md)
- [Cookie源码解析](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Cookie.md)
- [Contracts契约](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Contracts.md)
- [加载和读取ENV配置](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ENV.md)
- [HTTP内核](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/HttpKernel.md)
- [Console内核](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/ConsoleKernel.md)
- [异常处理](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Exception.md)
- [结束语](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Farewell.md)



## 其他推荐
- [Go Web编程入门指南](https://github.com/go-study-lab/go-http-server)
- [Laravel最佳实践](https://github.com/kevinyan815/laravel_best_practices_cn) 
- [我的Golang开发手记](https://github.com/kevinyan815/gocookbook)

## 招聘

给自己打一条广告，如果你觉得自己能达到PHP高级工程师的水平，觉得技术上有瓶颈，想扩展Go或者Java语言的经验但是现在的公司没有机会？或者觉得面试其他语言的工程师信心不足？
欢迎PHP工程师向我投递简历，我们现在PHP有少量系统需要维护，入职后给切换语言的过渡期，后期使用Go或者Java作为主开发语言。

如果你觉得这是个机会欢迎给我投递简历，我帮你们内推。我的联系方式上面有，邮箱和公众号联系我都可以。

公司坐标：北京市 | 东城区

