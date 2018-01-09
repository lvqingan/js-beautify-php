## Usage

```php
$obj = new JSBeautify('function(){alert("foo");}');
echo $obj->getResult();
```
to output will be
```
function () {
    alert("foo");
}
```
