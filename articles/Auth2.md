# 用户认证系统的实现细节

上一节我们介绍了Laravel Auth系统的基础知识，说了他的核心组件都有哪些构成，这一节我们会专注Laravel Auth系统的实现细节，主要关注`Auth`也就是`AuthManager`是如何装载认证用的看守器(Guard)和用户提供器(UserProvider)以及默认的用户注册和登录的实现细节，通过梳理这些实现细节我们也就能知道应该如何定制Auth认证来满足我们自己项目中用户认证的需求的。

### 通过AuthManager装载看守器和用户提供器

AuthManager装载看守器和用户提供器用到的方法比较多，用文字描述不太清楚，我们通过注解这个过程中用到的方法来看具体的实现细节。



```
namespace Illuminate\Auth;
class AuthManager implements FactoryContract
{
    /**
     * 尝试从config/auth.php 的guards中获取指定name对应的Guard
     *
     * @param  string  $name
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    public function guard($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();

        return isset($this->guards[$name])
                    ? $this->guards[$name]
                    : $this->guards[$name] = $this->resolve($name);
    }
    
    /**
     * 解析出给定name的Guard
     *
     * @param  string  $name
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     *
     * @throws \InvalidArgumentException
     */
    protected function resolve($name)
    {
        //获取Guard的配置
        //$config = ['driver' => 'session', 'provider' => 'users']
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Auth guard [{$name}] is not defined.");
        }
	   //如果通过extend方法为guard定义了驱动器，这里去调用自定义的Guard驱动器
        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($name, $config);
        }
        //Laravel auth默认的配置这里是执行createSessionDriver
        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($name, $config);
        }

        throw new InvalidArgumentException("Auth guard driver [{$name}] is not defined.");
    }
    
    /**
     * 从config/auth.php中获取给定名称的Guard的配置
     *
     * @param  string  $name
     * @return array
     */
    protected function getConfig($name)
    {
        //'guards' => [
        //    'web' => [
        //        'driver' => 'session',
        //        'provider' => 'users',
        //    ],

        //    'api' => [
        //        'driver' => 'token',
        //        'provider' => 'users',
        //    ],
        //],
        // 根据Laravel默认的auth配置， 这个方法会获取key "web"对应的数组
        return $this->app['config']["auth.guards.{$name}"];
    }
    
    /**
     * 调用自定义的Guard驱动器
     *
     * @param  string  $name
     * @param  array  $config
     * @return mixed
     */
    protected function callCustomCreator($name, array $config)
    {
        return $this->customCreators[$config['driver']]($this->app, $name, $config);
    }
    
    /**
     * 注册一个自定义的闭包Guard 驱动器 到customCreators属性中
     *
     * @param  string  $driver
     * @param  \Closure  $callback
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }
    
    /**
     * 注册一个自定义的用户提供器创建器到 customProviderCreators属性中
     *
     * @param  string  $name
     * @param  \Closure  $callback
     * @return $this
     */
    public function provider($name, Closure $callback)
    {
        $this->customProviderCreators[$name] = $callback;

        return $this;
    }
    
    /**
     * 创建基于session的认证看守器 SessionGuard
     *
     * @param  string  $name
     * @param  array  $config
     * @return \Illuminate\Auth\SessionGuard
     */
    public function createSessionDriver($name, $config)
    {
    	//$config['provider'] == 'users'
        $provider = $this->createUserProvider($config['provider'] ?? null);

        $guard = new SessionGuard($name, $provider, $this->app['session.store']);

        if (method_exists($guard, 'setCookieJar')) {
            $guard->setCookieJar($this->app['cookie']);
        }

        if (method_exists($guard, 'setDispatcher')) {
            $guard->setDispatcher($this->app['events']);
        }

        if (method_exists($guard, 'setRequest')) {
            $guard->setRequest($this->app->refresh('request', $guard, 'setRequest'));
        }

        return $guard;
    }
    
    //创建Guard驱动依赖的用户提供器对象
    public function createUserProvider($provider = null)
    {
        if (is_null($config = $this->getProviderConfiguration($provider))) {
            return;
        }
        //如果通过Auth::provider方法注册了自定义的用户提供器creator闭包则去调用闭包获取用户提供器对象
        if (isset($this->customProviderCreators[$driver = ($config['driver'] ?? null)])) {
            return call_user_func(
                $this->customProviderCreators[$driver], $this->app, $config
            );
        }

        switch ($driver) {
            case 'database':
                return $this->createDatabaseProvider($config);
            case 'eloquent':
                //通过默认的auth配置这里会返回EloquentUserProvider对象,它实现了Illuminate\Contracts\Auth 接口
                return $this->createEloquentProvider($config);
            default:
                throw new InvalidArgumentException(
                    "Authentication user provider [{$driver}] is not defined."
                );
        }
    }
    
    /**
     * 会通过__call去动态地调用AuthManager代理的Guard的用户认证相关方法
     * 根据默认配置，这里__call会去调用SessionGuard里的方法
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->guard()->{$method}(...$parameters);
    }
}


```

### 用户注册

Laravel Auth系统中默认的注册路由如下:

```
$this->post('register', 'Auth\RegisterController@register');
```

所以用户注册的逻辑是由RegisterController的register方法来完成的

```
class RegisterController extends Controller
{
    //方法定义在Illuminate\Foundation\Auth\RegisterUsers中
    public function register(Request $request)
    {
        $this->validator($request->all())->validate();

        event(new Registered($user = $this->create($request->all())));

        $this->guard()->login($user);

        return $this->registered($request, $user)
        
     }
     
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);
    }
    
    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);
    }
         
}
```

register的流程很简单，就是验证用户输入的数据没问题后将这些数据写入数据库生成用户，其中密码加密采用的是bcrypt算法，如果你需要改成常用的salt加密码明文做哈希的密码加密方法可以在create方法中对这部分逻辑进行更改，注册完用户后会调用SessionGuard的login方法把用户数据装载到应用中，注意这个login方法没有登录认证，只是把认证后的用户装载到应用中这样在应用里任何地方我们都能够通过`Auth::user()`来获取用户数据啦。



### 用户登录认证

Laravel Auth系统的登录路由如下

```
$this->post('login', 'Auth\LoginController@login');
```

我们看一下LoginController里的登录逻辑

```
class LoginController extends Controller
{
    /**
     * 处理登录请求
     */
    public function login(Request $request)
    {
        //验证登录字段
        $this->validateLogin($request);
        //防止恶意的多次登录尝试
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }
        //进行登录认证
        if ($this->attemptLogin($request)) {
            return $this->sendLoginResponse($request);
        }

        $this->incrementLoginAttempts($request);

        return $this->sendFailedLoginResponse($request);
    }
    
    //尝试进行登录认证
    protected function attemptLogin(Request $request)
    {
        return $this->guard()->attempt(
            $this->credentials($request), $request->filled('remember')
        );
    }
    
    //获取登录用的字段值
    protected function credentials(Request $request)
    {
        return $request->only($this->username(), 'password');
    }
}
```

可以看到，登录认证的逻辑是通过`SessionGuard`的`attempt`方法来实现的，其实就是`Auth::attempt()`， 下面我们来看看`attempt`方法里的逻辑:

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
```

`SessionGuard`的`attempt`方法首先通过用户提供器的`retriveBycredentials`方法通过用户名从用户表中查询出用户数据，认证用户信息是通过用户提供器的`validateCredentials`来实现的，所有用户提供器的实现类都会实现UserProvider契约(interface)中定义的方法，通过上面的分析我们知道默认的用户提供器是`EloquentUserProvider`



```
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
    public function validateCredentials(UserContract $user, array $credentials)
    {
        $plain = $credentials['password'];

        return $this->hasher->check($plain, $user->getAuthPassword());
    }
}

class BcryptHasher implements HasherContract
{
    //通过bcrypt算法计算给定value的散列值
    public function make($value, array $options = [])
    {
        $hash = password_hash($value, PASSWORD_BCRYPT, [
            'cost' => $this->cost($options),
        ]);

        if ($hash === false) {
            throw new RuntimeException('Bcrypt hashing not supported.');
        }

        return $hash;
    }
    
    //验证散列值是否给定明文值通过bcrypt算法计算得到的
    public function check($value, $hashedValue, array $options = [])
    {
        if (strlen($hashedValue) === 0) {
            return false;
        }

        return password_verify($value, $hashedValue);
    }
}
```

用户密码的验证是通过`EloquentUserProvider`依赖的`hasher`哈希器来完成的，Laravel认证系统默认采用bcrypt算法来加密用户提供的明文密码然后存储到用户表里的，验证时`haser`哈希器的`check`方法会通过PHP内建方法`password_verify`来验证明文密码是否是存储的密文密码的原值。



用户认证系统的主要细节梳理完后我们就知道如何定义我们自己的看守器(Guard)或用户提供器(UserProvider)了，首先他们必须实现各自遵守的契约里的方法才能够无缝接入到Laravel的Auth系统中，然后还需要将自己定义的Guard或Provider通过`Auth::extend`、`Auth::provider`方法注册返回Guard或者Provider实例的闭包到Laravel中去，Guard和UserProvider的自定义不是必须成套的，我们可以单独自定义Guard仍使用默认的EloquentUserProvider，或者让默认的SessionGuard使用自定义的UserProvider。 

下一节我会给出一个我们以前项目开发中用到的一个案例来更好地讲解应该如何对Laravel Auth系统进行扩展。



上一篇: [用户认证系统(基础介绍)](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth1.md)

下一篇: [扩展用户认证系统](https://github.com/kevinyan815/Learning_Laravel_Kernel/blob/master/articles/Auth3.md)

