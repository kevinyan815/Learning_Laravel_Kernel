# 扩展用户认证系统



上一节我们介绍了Laravel Auth系统实现的一些细节知道了Laravel是如何应用看守器和用户提供器来进行用户认证的，但是针对我们自己开发的项目或多或少地我们都会需要在自带的看守器和用户提供器基础之上做一些定制化来适应项目，本节我会列举一个在做项目时遇到的具体案例，在这个案例中用自定义的看守器和用户提供器来扩展了Laravel的用户认证系统让它能更适用于我们自己开发的项目。



在介绍用户认证系统基础的时候提到过Laravel自带的注册和登录验证用户密码时都是去验证采用`bcypt`加密存储的密码，但是很多已经存在的老系统中用户密码都是用盐值加明文密码做哈希后存储的，如果想要在这种老系统中应用Laravel开发项目的话那么我们就不能够再使用Laravel自带的登录和注册方法了，下面我们就通过实例看看应该如何扩展Laravel的用户认证系统让它能够满足我们项目的认证需求。



### 修改用户注册

首先我们将用户注册时，用户密码的加密存储的方式由`bcypt`加密后存储改为由盐值与明文密码做哈希后再存储的方式。这个非常简单，上一节已经说过Laravel自带的用户注册方法是怎么实现了，这里我们直接将`\App\Http\Controllers\Auth\RegisterController`中的`create`方法修改为如下：

```
/**
 * Create a new user instance after a valid registration.
 *
 * @param  array  $data
 * @return User
 */
protected function create(array $data)
{
    $salt = Str::random(6);
    return User::create([
        'email' => $data['email'],
        'password' => sha1($salt . $data['password']),
        'register_time' => time(),
        'register_ip' => ip2long(request()->ip()),
        'salt' => $salt
    ]);
}
```

上面改完后注册用户后就能按照我们指定的方式来存储用户数据了，还有其他一些需要的与用户信息相关的字段也需要存储到用户表中去这里就不再赘述了。

### 修改用户登录 

上节分析Laravel默认登录的实现细节时有说登录认证的逻辑是通过`SessionGuard`的`attempt`方法来实现的，在`attempt`方法中`SessionGuard`通过`EloquentUserProvider`的`retriveBycredentials`方法从用户表中查询出用户数据，通过 `validateCredentials`方法来验证给定的用户认证数据与从用户表中查询出来的用户数据是否吻合。

下面直接给出之前讲这块时引用的代码块:

```
class SessionGuard implements StatefulGuard, SupportsBasicAuth
{
    public function attempt(array $credentials = [], $remember = false)
    {
        $this->fireAttemptEvent($credentials, $remember);

        $this->lastAttempted = $user = $this->provider->retrieveByCredentials($credentials);
	   //如果登录认证通过，通过login方法将用户对象装载到应用里去
        if ($this->hasValidCredentials($user, $credentials)) {
            $this->login($user, $remember);

            return true;
        }
        //登录失败的话，可以触发事件通知用户有可疑的登录尝试(需要自己定义listener来实现)
        $this->fireFailedEvent($user, $credentials);

        return false;
    }
    
    protected function hasValidCredentials($user, $credentials)
    {
        return ! is_null($user) && $this->provider->validateCredentials($user, $credentials);
    }
}

class EloquentUserProvider implements UserProvider
{
    从数据库中取出用户实例
    public function retrieveByCredentials(array $credentials)
    {
        if (empty($credentials) ||
           (count($credentials) === 1 &&
            array_key_exists('password', $credentials))) {
            return;
        }

        $query = $this->createModel()->newQuery();

        foreach ($credentials as $key => $value) {
            if (! Str::contains($key, 'password')) {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }
    
    //通过给定用户认证数据来验证用户
    /**
     * Validate a user against the given credentials.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  array  $credentials
     * @return bool
     */
    public function validateCredentials(UserContract $user, array $credentials)
    {
        $plain = $credentials['password'];

        return $this->hasher->check($plain, $user->getAuthPassword());
    }
}
```

### 自定义用户提供器

好了， 看到这里就很明显了， 我们需要改成自己的密码验证就是自己实现一下`validateCredentials`就可以了， 修改`$this->hasher->check`为我们自己的密码验证规则。

首先我们来重写`$user->getAuthPassword();` 在User模型中覆盖其从父类中继承来的这个方法，把数据库中用户表的`salt`和`password`传递到`validateCredentials`中来:

```
class user extends Authenticatable
{
    /**
     * 覆盖Laravel中默认的getAuthPassword方法, 返回用户的password和salt字段
     * @return array
     */
    public function getAuthPassword()
    {
        return ['password' => $this->attributes['password'], 'salt' => $this->attributes['salt']];
    }
}    
```

然后我们用一个自定义的用户提供器，通过它的`validateCredentials`来实现我们自己系统的密码验证规则，由于用户提供器的其它方法不用改变沿用`EloquentUserProvider`里的实现就可以，所以我们让自定义的用户提供器继承自`EloquentUserProvider`：

```
namespace App\Foundation\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class CustomEloquentUserProvider extends EloquentUserProvider
{

    /**
     * Validate a user against the given credentials.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param array $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        $plain = $credentials['password'];
        $authPassword = $user->getAuthPassword();

        return sha1($authPassword['salt'] . $plain) == $authPassword['password'];
    }
}
```

接下来通过`Auth::provider()`将`CustomEloquentUserProvider`注册到Laravel系统中，`Auth::provider`方法将一个返回用户提供器对象的闭包作为用户提供器创建器以给定名称注册到Laravel中，代码如下:

```
class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \Auth::provider('custom-eloquent', function ($app, $config) {
            return New \App\Foundation\Auth\CustomEloquentUserProvider($app['hash'], $config['model']);
        });
    }
    ......
}
```

注册完用户提供器后我们就可以在`config/auth.php`里配置让看守器使用新注册的`custom-eloquent`作为用户提供器了:

```
//config/auth.php
'providers' => [
    'users' => [
        'driver' => 'coustom-eloquent',
        'model' => \App\User::class,
    ]
]
```

### 自定义认证看守器

好了，现在密码认证已经修改过来了，现在用户认证使用的看守器还是`SessionGuard`， 在系统中会有对外提供API的模块，在这种情形下我们一般希望用户登录认证后会返回给客户端一个JSON WEB TOKEN，每次调用接口时候通过这个token来认证请求接口的是否是有效用户，这个需求需要我们通过自定义的Guard扩展功能来完成，有个`composer`包`"tymon/jwt-auth": "dev-develop"`， 他的1.0beta版本带的`JwtGuard`是一个实现了`Illuminate\Contracts\Auth\Guard`的看守器完全符合我上面说的要求，所以我们就通过`Auth::extend()`方法将`JwtGuard`注册到系统中去:

```
class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \Auth::provider('custom-eloquent', function ($app, $config) {
            return New \App\Foundation\Auth\CustomEloquentUserProvider($app['hash'], $config['model']);
        });
        
        \Auth::extend('jwt', function ($app, $name, array $config) {
            // 返回一个 Illuminate\Contracts\Auth\Guard 实例...
	    return new \Tymon\JWTAuth\JwtGuard(\Auth::createUserProvider($config['provider']));
        });
    }
    ......
}
```



定义完之后，将 `auth.php` 配置文件的`guards`配置修改如下：

```
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'jwt', // token ==> jwt
        'provider' => 'users',
    ],
],
```

接下来我们定义一个API使用的登录认证方法， 在认证中会使用上面注册的`jwt`看守器来完成认证，认证完成后会返回一个JSON WEB TOKEN给客户端

```
Route::post('apilogin', 'Auth\LoginController@apiLogin');
```

```
class LoginController extends Controller
{
   public function apiLogin(Request $request)
   {
        ...
	
        if ($token = $this->guard('api')->attempt($credentials)) {
            $return['status_code'] = 200;
            $return['message'] = '登录成功';
            $response = \Response::json($return);
            $response->headers->set('Authorization', 'Bearer '. $token);
            return $response;
        }

    ...
    }
}
```



### 总结

通过上面的例子我们讲解了如何通过自定义认证看守器和用户提供器扩展Laravel的用户认证系统，目的是让大家对Laravel的用户认证系统有一个更好的理解知道在Laravel系统默认自带的用户认证方式无法满足我们的需求时如何通过自定义这两个组件来扩展功能完成我们项目自己的认证需求。

上一篇: [用户认证系统(实现细节)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth2.md)

下一篇: [Session](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Session.md)
