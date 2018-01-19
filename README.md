## Usage

```php
$obj = new JSBeautify('function(){alert("foo");}');
echo $obj->getResult();
```

the output will be

```
function () {
    alert("foo");
}
```
