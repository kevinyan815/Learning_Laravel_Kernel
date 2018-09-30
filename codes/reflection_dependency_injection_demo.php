<?php
class Circle
{
    /**
     * @var int
     */
	public $radius;

    /**
     * @var Point
     */
	public $center;

    const PI = 3.14;

	public function __construct(Point $point, $radius = 1)
	{
		$this->center = $point;
		$this->radius = $radius;
	}

	public function printCenter()
    {
        printf('center coordinate is (%d, %d)', $this->center->x, $this->center->y);
    }

    public function area()
    {
        return 3.14 * pow($this->radius, 2);
    }
}

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


$reflectionClass = new reflectionClass(Circle::class);


function make($className)
{
    $reflectionClass = new ReflectionClass($className);
    $constructor = $reflectionClass->getConstructor();
    $parameters  = $constructor->getParameters();
    $dependencies = getDependencies($parameters);

    return $reflectionClass->newInstanceArgs($dependencies);
}

function getDependencies($parameters)
{
    $dependencies = [];
    foreach($parameters as $parameter) {
        $dependency = $parameter->getClass();
        if (is_null($dependency)) {
            if($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } else {
                //to easily implement this function, I just assume 0 to built-in type parameters
                $dependencies[] = '0';
            }
        } else {
            $dependencies[] = make($parameter->getClass()->name);
        }
    }

    return $dependencies;
}

$circle = make('Circle');
$area = $circle->area();
