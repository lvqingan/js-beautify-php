## Usage

```php
$obj = new JSBeautify('function escapeHtml(string){return String(string).replace(/[&<>"'`=\/]/g,function fromEntityMap(s){return entityMap[s]})}');
echo $obj->getResult();
```

the output will be

```
function escapeHtml(string) {
    return String(string).replace(/[&<>"'`=\/]/g, function fromEntityMap(s) {
        return entityMap[s]
    })
}
```
