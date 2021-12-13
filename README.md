Yii2 Template Engine
====================

Simple, fast and flexible HTML templating engine for Yii2 PHP framework with zero configuration.
Supports basic **control structures** (IF, FOR, SET), **dynamic directives** and **active records relations**.
It is similar to [Twig](https://twig.symfony.com/) or [Blade](https://laravel.com/docs/8.x/blade), though much simpler.

Installation
============

```bash
$ composer require "lubosdz/yii2-template-engine : ~1.0"
```


Basic usage
===========

Initiate template engine inside Yii application:

~~~php
$template = new \lubosdz\yii2\TemplateEngine;
~~~

or register as component in `app/config/main.php`:

~~~php
...
'components' => [
	'template' => [
		'class' => 'lubosdz\yii2\TemplateEngine',
	]
]
...

$template = Yii::$app->template;
~~~

Use method `render($html [, $values])` to generate HTML output:

* $html = source HTML markup with placeholders like `{{ processMe }}`
* $values = array of values to be injected into or evaluated inside placeholders


Simple placeholders
-------------------

~~~php
$params = ['who' => 'world'];
$html = $template->render('Hello <b>{{ who }}</b>.', $params);
// output: "Hello <b>world</b>."
~~~


Built-in directives
-------------------

Template engine comes with couple of generally usable methods for basic date,
time and string manipulation. These can be referenced directly inside
the template markup. Directives use the pipe operator `|` to chain operations
within the placeholder.

~~~php
$html = $template->render('Generated on {{ today }} at {{ time }}.');
// output example - respects locales: "Generated on Dec 31, 2021 at 11:59pm."

$html = $template->render('Let's meet at {{ now(7200) | time }}.');
// output example, if now is 8:30am: "Let's meet at 10:30am." (shift +2 hours)

$html = $template->render('My name is {{ user.name | escape }}.', ['user' => [
	'name' => '<John>',
]]);
// output: "My name is &lt;John&gt;."

$html = $template->render('Hello {{ user.name | truncate(5) | escape }}.', ['user' => [
	'name' => '<John>',
]]);
// output: "Hello &lt;John...."
~~~

Specific for the Yii 2 framework, object properties as well as related models (AR)
are automatically collected, if referenced inside placeholders.

~~~php
$html = $template->render('Hello {{ customer.name }}.', [
	'user' => Customer::findOne($id),
]);
// output e.g.: "Hello John Doe."

$html = $template->render('Address is {{ customer.address.city }} {{ customer.address.zip }}.', [
	'user' => Customer::findOne($id), // customer has defined relation to object `Address`
]);
// output e.g.: "Address is Prague 10000."
~~~

IF .. ELSEIF .. ELSE .. ENDIF
----------

Control structure `IF .. ELSEIF .. ELSE .. ENDIF` is supported:

~~~php
$parse = "
{{ if countOrders > 0 }}
	<h3>Thank you!</h3>
{{ else }}
	<h3>Sure you want to leave?</h3>
{{ endif }}
";

$values = [
	'countOrders' => count(Customer::findOne($id)->orders);
];

$html = $template->render($parse, $values);
// output e.g.: "<h3>Thank you!</h3>" - if some order found
~~~

FOR ... ELSEFOR .. ENDFOR
-----------

Structure `FOR ... ELSEFOR .. ENDFOR` will create loop:

~~~php
$parse = "
<table>
{{ for item in items }}
	{{ SET subtotal = item.qty * item.price * (100 + item.vat) / 100 }}
	<tr>
		<td>#{{ loop.index }}</td>
		<td>{{ item.description }}</td>
		<td>{{ item.qty }}</td>
		<td>{{ item.price | round(2) }}</td>
		<td>{{ item.vat | round(2) }}%</td>
		<td>{{ subtotal | round(2) }} &euro;</td>
	</tr>
	{{ SET total = total + subtotal }}
{{ endfor }}
</table>
<p>Amount due: <b> {{ total | round(2) }} Eur</b></p>
";

$values = [
	'items' => [
		['description' => 'Item one', 'qty' => 1, 'price' => 1, 'vat' => 10],
		['description' => 'Item two', 'qty' => 2, 'price' => 2, 'vat' => 20],
		['description' => 'Item three', 'qty' => 3, 'price' => 3, 'vat' => 30],
		['description' => 'Item four', 'qty' => 4, 'price' => 4, 'vat' => 40],
	]
];

$html = $template->render($parse, $values);
// outputs valid HTML table with items e.g.: "<table><tr><td>#1</td><td> ..."
~~~

Following auxiliary variables are available inside each loop:

* `index` .. (int) 1-based iteration counter
* `index0` .. (int) 0-based iteration counter
* `length` .. (int) total number of items/iterations
* `first` .. (bool) true on first iteration
* `last` .. (bool) true on last iteration

SET command
-----------

Allows manipulating local template variables:

```php
{{ SET subtotal = item.qty * item.price * (100 + item.vat) / 100 }}
{{ SET total = total + subtotal }}
```

See also example under `IF`.

Note: shorthand syntax `+=` e.g. `SET total += subtotal` is NOT supported.


Tips
====

* see `tests/TemplateEngineTest.php` for more examples
* Running tests via phpunit on [NuSphere's PhpEd](http://www.nusphere.com/) in debug mode:

> use `-d` parameter to inject debugging arguments, e.g. `> phpunit -d DBGSESSID=12655478@127.0.0.1:7869`

* Adding functionality - simply extend `TemplateEngine` class:

~~~php
class MyRenderer extends \lubosdz\yii2\TemplateEngine
{
	// your code, directives, overrides ..
}
~~~

Changelog
=========

1.0.0, released 2021-12-13
--------------------------

* initial release
