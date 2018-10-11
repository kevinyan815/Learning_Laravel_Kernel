# 用户认证系统(基础介绍)

使用过Laravel的开发者都知道，Laravel自带了一个认证系统来提供基本的用户注册、登录、认证、找回密码，如果Auth系统里提供的基础功能不满足需求还可以很方便的在这些基础功能上进行扩展。这篇文章我们先来了解一下Laravel Auth系统的核心组件。

Auth系统的核心是由 Laravel 的认证组件的「看守器」和「提供器」组成。看守器定义了该如何认证每个请求中用户。例如，Laravel 自带的 session 看守器会使用 session 存储和 cookies 来维护状态。

下表列出了Laravel Auth系统的核心部件

| 名称          | 作用                                                         |
| ------------- | ------------------------------------------------------------ |
| Auth          | AuthManager的Facade                                          |
| AuthManager   | Auth认证系统面向外部的接口，认证系统通过它向应用提供所有与用户认证相关的功能|
| Guard         | 看守器，定义了该如何认证每个请求中用户                       |
| User Provider | 用户提供器，定义了如何从持久化的存储数据中检索用户           |

在本文中我们会详细介绍这些核心部件，然后在文章的最后更新每个部件的作用细节到上面给出的这个表中。



### 开始使用Auth系统

只需在新的 Laravel 应用上运行 `php artisan make:auth` 和 `php artisan migrate` 命令就能够在项目里生成Auth系统需要的路由和视图以及数据表。

`php artisan make:auth`执行后会生成Auth认证系统需要的视图文件，此外还会在路由文件`web.php`中增加响应的路由:

```
Auth::routes();
```

`Auth` Facade文件中单独定义了`routes`这个静态方法

```
public static function routes()
{
    static::$app->make('router')->auth();
}
```

所以Auth具体的路由方法都定义在`Illuminate\Routing\Router`的`auth`方法中，关于如何找到Facade类代理的实际类可以翻看之前Facade源码分析的章节。



```
namespace Illuminate\Routing;
class Router implements RegistrarContract, BindingRegistrar
{
    /**
     * Register the typical authentication routes for an application.
     *
     * @return void
     */
    public function auth()
    {
        // Authentication Routes...
        $this->get('login', 'Auth\LoginController@showLoginForm')->name('login');
        $this->post('login', 'Auth\LoginController@login');
        $this->post('logout', 'Auth\LoginController@logout')->name('logout');

        // Registration Routes...
        $this->get('register', 'Auth\RegisterController@showRegistrationForm')->name('register');
        $this->post('register', 'Auth\RegisterController@register');

        // Password Reset Routes...
        $this->get('password/reset', 'Auth\ForgotPasswordController@showLinkRequestForm')->name('password.request');
        $this->post('password/email', 'Auth\ForgotPasswordController@sendResetLinkEmail')->name('password.email');
        $this->get('password/reset/{token}', 'Auth\ResetPasswordController@showResetForm')->name('password.reset');
        $this->post('password/reset', 'Auth\ResetPasswordController@reset');
    }
}
```

在`auth`方法里可以清晰的看到认证系统里提供的所有功能的路由URI以及对应的控制器和方法。



使用Laravel的认证系统，几乎所有东西都已经为你配置好了。其配置文件位于 `config/auth.php`，其中包含了用于调整认证服务行为的注释清晰的选项配置。

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 认证的默认配置
    |--------------------------------------------------------------------------
    |
    | 设置了认证用的默认"看守器"和密码重置的选项
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | 定义项目使用的认证看守器，默认的看守器使用session驱动和Eloquent User 用户数据提供者
    |
    | 所有的驱动都有一个用户提供者，它定义了如何从数据库或者应用使用的持久化用户数据的存储中取出用户信息
    |
    | Supported: "session", "token"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'token',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | 所有的驱动都有一个用户提供者，它定义了如何从数据库或者应用使用的持久化用户数据的存储中取出用户信息
    |
    | Laravel支持通过不同的Guard来认证用户，这里可以定义Guard的用户数据提供者的细节:
    |        使用什么driver以及对应的Model或者table是什么
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 重置密码相关的配置
    |--------------------------------------------------------------------------
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60,
        ],
    ],

];
```

Auth系统的核心是由 Laravel 的认证组件的「看守器」和「提供器」组成。看守器定义了该如何认证每个请求中用户。例如，Laravel 自带的 `session` 看守器会使用 session 存储和 cookies 来维护状态。

提供器中定义了该如何从持久化的存储数据中检索用户。Laravel 自带支持使用 Eloquent 和数据库查询构造器来检索用户。当然，你可以根据需要自定义其他提供器。

所以上面的配置文件的意思是Laravel认证系统默认使用了web guard配置项， 配置项里使用的是看守器是SessionGuard，使用的用户提供器是`EloquentProvider` 提供器使用的model是`App\User`。

### Guard

看守器定义了该如何认证每个请求中的用户。Laravel自带的认证系统默认使用自带的 `SessionGuard` ，`SessionGuard`除了实现`\Illuminate\Contracts\Auth\Guard`契约里的方法还实现`Illuminate\Contracts\Auth\StatefulGuard` 和`Illuminate\Contracts\Auth\SupportsBasicAuth`契约里的方法，这些Guard Contracts里定义的方法都是Laravel Auth系统默认认证方式依赖的基础方法。

我们先来看一下这一些基础方法都意欲完成什么操作，等到分析Laravel是如何通过SessionGuard认证用户时在去关系这些方法的具体实现。

#### Illuminate\Contracts\Auth\Guard

这个文件定义了基础的认证方法

```
namespace Illuminate\Contracts\Auth;

interface Guard
{
    /**
     * 返回当前用户是否时已通过认证，是返回true，否者返回false
     *
     * @return bool
     */
    public function check();

    /**
     * 验证是否时访客用户(非登录认证通过的用户)
     *
     * @return bool
     */
    public function guest();

    /**
     * 获取当前用户的用户信息数据，获取成功返回用户User模型实例(\App\User实现了Authenticatable接口)
     * 失败返回null
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function user();

    /**
     * 获取当前认证用户的用户ID，成功返回ID值，失败返回null
     *
     * @return int|null
     */
    public function id();

    /**
     * 通过credentials(一般是邮箱和密码)验证用户
     *
     * @param  array  $credentials
     * @return bool
     */
    public function validate(array $credentials = []);

    /**
     * 将一个\App\User实例设置成当前的认证用户
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @return void
     */
    public function setUser(Authenticatable $user);
}

```

#### Illuminate\Contracts\Auth\StatefulGuard

这个Contracts定义了Laravel auth系统里认证用户时使用的方法，除了认证用户外还会涉及用户认证成功后如何持久化用户的认证状态。

```
<?php

namespace Illuminate\Contracts\Auth;

interface StatefulGuard extends Guard
{
    /**
     * Attempt to authenticate a user using the given credentials.
     * 通过给定用户证书来尝试认证用户，如果remember为true则在一定时间内记住登录用户
     * 认证通过后会设置Session和Cookies数据
     * @param  array  $credentials
     * @param  bool   $remember
     * @return bool
     */
    public function attempt(array $credentials = [], $remember = false);

    /**
     * 认证用户，认证成功后不会设置session和cookies数据
     *
     * @param  array  $credentials
     * @return bool
     */
    public function once(array $credentials = []);

    /**
     * 登录用户（用户认证成功后设置相应的session和cookies)
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  bool  $remember
     * @return void
     */
    public function login(Authenticatable $user, $remember = false);

    /**
     * 通过给定的用户ID登录用户
     *
     * @param  mixed  $id
     * @param  bool   $remember
     * @return \Illuminate\Contracts\Auth\Authenticatable
     */
    public function loginUsingId($id, $remember = false);

    /**
     * 通过给定的用户ID登录用户并且不设置session和cookies
     *
     * @param  mixed  $id
     * @return bool
     */
    public function onceUsingId($id);

    /**
     * Determine if the user was authenticated via "remember me" cookie.
     * 判断用户是否时通过name为"remeber me"的cookie值认证的
     * @return bool
     */
    public function viaRemember();

    /**
     * 登出用户
     *
     * @return void
     */
    public function logout();
}


```

#### Illuminate\Contracts\Auth\SupportsBasicAuth

定义了通过Http Basic Auth 认证用户的方法

```
namespace Illuminate\Contracts\Auth;

interface SupportsBasicAuth
{
    /**
     * 尝试通过HTTP Basic Auth来认证用户
     *
     * @param  string  $field
     * @param  array  $extraConditions
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    public function basic($field = 'email', $extraConditions = []);

    /**
     * 进行无状态的Http Basic Auth认证 (认证后不会设置session和cookies)
     *
     * @param  string  $field
     * @param  array  $extraConditions
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    public function onceBasic($field = 'email', $extraConditions = []);
}
```
### User Provider

用户提供器中定义了该如何从持久化的存储数据中检索用户，Laravel定义了用户提供器契约(interface)，所有用户提供器都要实现这个接口里定义的抽象方法，因为实现了统一的接口所以使得无论是Laravel 自带的还是自定义的用户提供器都能够被Guard使用。



#### 用户提供器契约

如下是契约中定义的必需被用户提供器实现的抽象方法:



```
<?php

namespace Illuminate\Contracts\Auth;

interface UserProvider
{
    /**
     * 通过用户唯一ID获取用户数据
     *
     * @param  mixed  $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveById($identifier);

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     * 通过Cookies中的"remeber me"令牌和用户唯一ID获取用户数据
     * @param  mixed   $identifier
     * @param  string  $token
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByToken($identifier, $token);

    /**
     * 更新数据存储中给定用户的remeber me令牌
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  string  $token
     * @return void
     */
    public function updateRememberToken(Authenticatable $user, $token);

    /**
     * 通过用户证书获取用户信息
     *
     * @param  array  $credentials
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByCredentials(array $credentials);

    /**
     * 验证用户的证书
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  array  $credentials
     * @return bool
     */
    public function validateCredentials(Authenticatable $user, array $credentials);
}
```



通过配置文件`config/auth.php`可以看到Laravel默认使用的用户提供器是`Illuminate\Auth\EloquentUserProvider` , 下一章节我们分析Laravel Auth系统实现细节的时候我们再来看看`EloquentUserProvider`是怎么实现用户提供器契约中的抽象方法的。



### 总结



本节我们主要介绍Laravel Auth系统的基础，包括Auth系统的核心组件看守器和提供器，AuthManager通过调用配置文件里指定的看守器来完成用户认证，在认证过程需要的用户数据是看守器通过用户提供器获取到的，下面的表格里总结了Auth系统的核心部件以及每个部件的作用。

| 名称          | 作用                                                         |
| ------------- | ------------------------------------------------------------ |
| Auth          | AuthManager的Facade                                          |
| AuthManager   | Auth认证系统面向外部的接口，认证系统通过它向应用提供所有Auth用户认证相关的方法，而认证方法的具体实现细节由它代理的具体看守器（Guard）来完成。 |
| Guard         | 看守器，定义了该如何认证每个请求中用户，认证时需要的用户数据会通过用户数据提供器来获取。 |
| User Provider | 用户提供器，定义了如何从持久化的存储数据中检索用户，Guard认证用户时会通过提供器取用户的数据，所有的提供器都是\Illuminate\Contracts\Auth\UserProvider接口的实现，提供了从持久化存储中取用户数据的具体实现细节。 |

下一章节我们会看看Laravel自带的用户认证功能的实现细节。

上一篇: [事件系统](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Event.md)

下一篇: [用户认证系统(实现细节)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth2.md)
