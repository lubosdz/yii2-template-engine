<?php
/**
* Tests for Yii2 templating engine
* Tests passing PHP 7.0 - 8.2
*/

use lubosdz\yii2\TemplateEngine;
use PHPUnit\Framework\TestCase;

class TemplateEngineTest extends TestCase
{
	protected $engine;

	/**
	* Run before each test is started
	* We intentionally avoid setUp() because it requires different syntax depending on PHP version:
	* - PHP 8.1+ requires parent's compatible syntax, means adding return type : void
	* - PHP 7.1 - 8.0 will run, void supported
	* - PHP 7.0 - does not recognize void
	* We want simply run tests on any version 7.0+ without adjusting the code.
	* Return types are not needed, neither strict mode
	*/
	protected function setUpIndependentOfPhpVersion()
	{
		// initiate static property Yii::$app
		self::mockApp();

		// init templating engine - either via namespace or as Yii component
		$this->engine = new TemplateEngine;

		// turn off logging since we dont have database setup for tests
		$this->engine->setLogErrors(false);
	}

	/**
	* Test replacing placeholders with scalars and models (ActiveRecord, ActiveForm)
	*/
	public function testModelsAndScalars()
	{
		$this->setUpIndependentOfPhpVersion();

		$engine = $this->engine;
		$formatter = Yii::$app->formatter;
		$client = new Customer();

		// simple models
		$params = [
			$client, // numeric key [0] => model, we must refer it via model's classname "customer" (case insensitive)
			'oneTwoThree' => '1-2-3',
		];

		$html = 'Just called customer #{{ customer.id }} - {{ customer.email }}.';
		$resultHtml = $engine->render($html, $params);
		$expectedHtml = 'Just called customer #'.$client->id.' - '.$client->email.'.';
		$this->assertEquals($expectedHtml, $resultHtml);

		// multiple model instances
		$params += [
			// A/ supply as object with non-numeric unique key (case insensitive)
			//    note: in this case relations work as well e.g. "anotherClient.address.city"
			'anotherClient' => $client,

			// B/ or supply as an array with object attributes
			//    note: in this case relations don't work (!)
			//'anotherClient' => $client->getAttributes(),
		];

		$html = 'Another customer #{{ anotherClient.id }} - {{ anotherClient.email }}.';
		$resultHtml = $engine->render($html, $params);
		$expectedHtml = 'Another customer #'.$client->id.' - '.$client->email.'.';
		$this->assertEquals($expectedHtml, $resultHtml);

		// date, time
		$html = 'Customer created on {{ customer.datetime_created | date }} at {{ customer.datetime_created | time }}.';
		$resultHtml = $engine->render($html, $params);

		$expectedHtml = 'Customer created on '.$formatter->asDate($client->datetime_created).' at '.$formatter->asTime($client->datetime_created, 'short').'.';
		$this->assertEquals($expectedHtml, $resultHtml);

		$html = 'Generated {{ today }} at {{ now | time }}.';
		$resultHtml = $engine->render($html);
		$time = $formatter->asTime(time(), 'short');
		$today = $formatter->asDate(time());
		$this->assertTrue(false !== strpos($resultHtml, $time) && false !== strpos($resultHtml, $today));

		// escaping
		$html = 'Generated at {{ dirtyTag }} hey!';
		$params['dirtyTag'] = '<img src="https://google.com/downloadsomething?page=1&size=999" />';
		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false !== strpos($resultHtml, $params['dirtyTag']));

		$html = 'Generated on {{ dirtyTag | escape }} hey!';
		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false === strpos($resultHtml, $params['dirtyTag'])
			&& false !== strpos($resultHtml, '&lt;img ')
			&& false !== strpos($resultHtml, '&quot;')
			&& false !== strpos($resultHtml, '&amp;')
		);

		$html = 'Generated on {{ dirtyTag | e }} hey!';
		$resultHtml = $engine->render($html, $params);
		$this->assertEquals($resultHtml, 'Generated on &lt;img src=&quot;https://google.com/downloadsomething?page=1&amp;size=999&quot; /&gt; hey!');

		// nl2br
		$params['dirtyTag'] = <<<HTML
LINE111
LINE222
LINE333
HTML;
		$html = 'I have 2 brackets in "{{ dirtyTag | nl2br }}" now!';
		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false === strpos($resultHtml, $params['dirtyTag']) && 2 == substr_count($resultHtml, '<br />'));

		// truncate
		$params['dirtyTag'] = "A veeery looong cuuustomeeer name";
		$html = 'This is truncated string in "{{ dirtyTag | truncate(8) }}".';
		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false === strpos($resultHtml, $params['dirtyTag']) && strpos($resultHtml, '"A veeery..."'));

		$html = 'This is truncated string in "{{ dirtyTag | truncate(8; XYZ) }}" now!'; // delimiter argument
		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false === strpos($resultHtml, $params['dirtyTag']) && false !== strpos($resultHtml, '"A veeeryXYZ"'));

		// invalid placeholders not replaced - default behaviour, e.g. during development
		$html = 'Customer created on {{ oneTwoThree }} at {{ oneTwoNotReplaced }}.';
		$resultHtml = $engine->render($html, $params);

		$expectedHtml = 'Customer created on 1-2-3 at {{ oneTwoNotReplaced }}.';
		$this->assertTrue(false !== strpos($resultHtml, '1-2-3') && false !== strpos($resultHtml, 'oneTwoNotReplaced'));

		// invalid placeholders replaced with empty string - e.g. more suitable for production output
		$engine->setForceReplace(true);
		$resultHtml = $engine->render($html, $params);
		$expectedHtml = 'Customer created on 1-2-3 at {{ oneTwoNotReplaced }}.';
		$this->assertTrue(false !== strpos($resultHtml, '1-2-3') && false === strpos($resultHtml, 'oneTwoNotReplaced'));

		// test forced stringual placeholder
		$html = 'Evaluating ... [xxx {{ nonExistend }} yyy]';
		$engine->setForceReplace('---');
		$resultHtml = $engine->render($html);
		$this->assertTrue($resultHtml == "Evaluating ... [xxx --- yyy]");

		$resultHtml = $engine->render($html, ['nonExistend' => 'ooo']);
		$this->assertTrue($resultHtml == "Evaluating ... [xxx ooo yyy]");

		$resultHtml = $engine->render($html, ['nonExistendxxx' => 'ooo']);
		$this->assertTrue($resultHtml == "Evaluating ... [xxx --- yyy]");

		$resultHtml = $engine->render($html, ['nonExisten' => 'ooo']);
		$this->assertTrue($resultHtml == "Evaluating ... [xxx --- yyy]");

		$resultHtml = $engine->render($html, ['nonExistenD' => 'ooo']);
		$this->assertTrue($resultHtml == "Evaluating ... [xxx --- yyy]");

		$engine->setForceReplace('');
		$resultHtml = $engine->render($html);
		$this->assertTrue($resultHtml == "Evaluating ... [xxx  yyy]");

		$engine->setForceReplace(false);
		$resultHtml = $engine->render($html);
		$this->assertTrue($resultHtml == $html);

		// concatenate
		$html = 'This is {{ concat(customer.name) | concat("with contact email") | concat(customer.email) }}.';
		$result = $engine->render($html, $params);
		$expected = "This is John Doe with contact email john@doe.com.";
		$this->assertTrue($result == $expected);

		$html = 'This is {{ concat(customer.name; unescapedChars) }}.';
		$result = $engine->render($html, $params);
		$expected = "This is John Doe."; // invalid and improperly quoted argument is ignored
		$this->assertTrue($result == $expected);

		$html = 'This is {{ concat(customer.name) | concat("our great hero"; " - ") }}!';
		$result = $engine->render($html, $params);
		$expected = "This is John Doe - our great hero!";
		$this->assertTrue($result == $expected);

		// alternate argument separator
		$html = 'This is <b>{{ concat("AAA"; "-") | concat(\'BBB\', \'+\') }}</b>';
		$result = $engine->render($html);
		$this->assertTrue($result == "This is <b>AAA</b>"); // BBB is ignored due to wrong comma separator [,]

		$engine->setArgSeparator(',');
		$result = $engine->render($html);
		$this->assertTrue($result == "This is <b>BBB</b>"); // AAA is ignored due to invalid comma separator [,]

		// restore arg separator
		$engine->setArgSeparator(';');

		// trim
		$client->name = '+-   John Doe   +-';
		$html = ' aaa {{ customer.name }} bbb ';
		$result = $engine->render($html, $params);
		$expected = " aaa +-   John Doe   +- bbb ";
		$this->assertTrue($result == $expected);

		$html = ' aaa {{ customer.name | trim(+-) }} bbb ';
		$result = $engine->render($html, $params);
		$expected = " aaa    John Doe    bbb ";
		$this->assertTrue($result == $expected);

		// replace
		$engine->setArgSeparator(',');
		$html = ' HELLO {{ name | replace ( BADBOY ,  GOODBOY ) }}! ';
		$params = ['name' => 'BADBOY'];
		$result = $engine->render($html, $params);
		$this->assertTrue(false !== strpos($result, "HELLO GOODBOY"));

		$html = ' HELLO {{ name | replace ( " BOY",  "GIRL" ) }}! ';
		$params = ['name' => 'BAD BOY'];
		$result = $engine->render($html, $params);
		$this->assertTrue(false !== strpos($result, "HELLO BADGIRL"));

		$html = ' HELLO {{ name | replace ( "/boy/i", "friend1" ) | replace ( /GIRL/i, \'friend2\' ) }}! ';
		$params = ['name' => 'BAD BOY - GOOD GIRL'];
		$result = $engine->render($html, $params);
		$this->assertTrue($result == " HELLO BAD friend1 - GOOD friend2! ");
	}

	/**
	* Test dynamic directoves - callable named functions accepting arguments
	*/
	public function testDynamicDirectives()
	{
		$this->setUpIndependentOfPhpVersion();

		// attach dynamic directive
		$this->engine->setDirective('coloredText', function($text, $color){
			return "<span style='color: {$color}'>{$text}</span>";
		});

		// output: "this is <span style='color: yellow'>colored text</span>"
		$result = $this->engine->render("this is {{ output | coloredText(yellow) }}", [
			'output' => 'colored text',
		]);

		$this->assertTrue(false !== strpos($result, 'yellow'));
	}

	/**
	* Test IF
	*/
	public function test_If()
	{
		$this->setUpIndependentOfPhpVersion();

		$engine = $this->engine;

		$params = [
			'condition' => 5,
			new Customer(),
		];

		$html = <<<HTML
{{ if condition == 5 && customer.id > 0 }}
	MATCH #1 - CONDITION IS EXACTLY 5 for customer ID. {{ customer.id }} created on {{ customer.datetime_created | date }}.
{{ elseif condition > 5 }}
	MATCH #2 - CONDITION IS OVER 5.
{{ elseif condition < 0 }}
	MATCH #3 - CONDITION IS NEGATIVE.
{{ else }}
	MATCH #4 - CONDITION IS LESS THAN 5 AND GREATER THAN 0.
{{ endif }}
HTML;

		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false !== strpos($resultHtml, 'MATCH #1') && false === strpos($resultHtml, '{{'));

		$params = ['condition' => 8];
		$resultHtml = $engine->render($html, $params);
		$this->assertEquals($resultHtml, 'MATCH #2 - CONDITION IS OVER 5.');

		$params = ['condition' => 0.5];
		$resultHtml = $engine->render($html, $params);
		$this->assertEquals($resultHtml, 'MATCH #4 - CONDITION IS LESS THAN 5 AND GREATER THAN 0.');

		$params = ['condition' => -1];
		$resultHtml = $engine->render($html, $params);
		$this->assertTrue(false !== strpos($resultHtml, 'MATCH #3'));

		// quoted string inside IF condition
		$html = 'Evaluating ... [x{{ if 123 == "abc" }} FIRST {{ELSE}} SECOND {{ endif }}y]';
		$resultHtml = $engine->render($html);
		$this->assertTrue(false !== strpos($resultHtml, '[xSECONDy]') && false === strpos($resultHtml, '{{'));
		$this->assertEmpty($engine->getErrors());

		$html = 'Evaluating ... [x{{ if 123 != "abc" }} FIRST {{ELSE}} SECOND {{ endif }}y]';
		$resultHtml = $engine->render($html);
		$this->assertTrue(false !== strpos($resultHtml, '[xFIRSTy]') && false === strpos($resultHtml, '{{'));
		$this->assertEmpty($engine->getErrors());

		$html = 'Evaluating ... [xxx {{ nonExistend }} yyy]';
		$engine->setForceReplace('---');
		$result = $engine->render($html);
		$this->assertTrue($result == "Evaluating ... [xxx --- yyy]");

		$result = $engine->render($html, ['nonExistend' => 'ooo']);
		$this->assertTrue($result == "Evaluating ... [xxx ooo yyy]");

		$result = $engine->render($html, ['nonExistendxxx' => 'ooo']);
		$this->assertTrue($result == "Evaluating ... [xxx --- yyy]");

		$result = $engine->render($html, ['nonExisten' => 'ooo']);
		$this->assertTrue($result == "Evaluating ... [xxx --- yyy]");

		$result = $engine->render($html, ['nonExistenD' => 'ooo']);
		$this->assertTrue($result == "Evaluating ... [xxx --- yyy]");

		// deep chaining
		$result = $engine->render("Hello {{aaa.bbb.ccc.ddd}}!", [
			'aaa' => [
				'bbb' => [
					'ccc' => [
						'ddd' => 'world',
					]
				],
			],
		]);

		$this->assertTrue($result == "Hello world!");

		// atomic boolean / single variable
		$html = "Hello {{ if user }} {{user.name}} {{ else }} NOBODY {{ endif }}!";
		$params = ['user' => ['name' => 'JOHN']];
		$result = $engine->render($html, $params);
		$this->assertTrue($result == "Hello JOHN!");

		$params = ['user' => []];
		$result = $engine->render($html, $params);
		$this->assertTrue($result == "Hello NOBODY!", $result);

		$result = $engine->render($html, []);
		$this->assertTrue($result == "Hello NOBODY!", $result);

		$params = ['user' => ['surname' => 'Doe']];
		$result = $engine->render($html, $params);
		$this->assertTrue($result == "Hello ---!", $result);

		// numeric keys - edge case
		$html = "Hello {{ if 0 }} {{user.surname}} {{ else }} NOBODY {{ endif }}!";
		$result = $engine->render($html, $params);
		$this->assertTrue($result == "Hello NOBODY!", $result);

		$html = "Hello {{ if 1 }} {{user.surname}} {{ else }} NOBODY {{ endif }}!";
		$result = $engine->render($html, $params);
		$this->assertTrue($result == "Hello Doe!", $result);
	}

	/**
	* Test loop FOR and SET
	*/
	public function test_ForAndSet()
	{
		$this->setUpIndependentOfPhpVersion();

		$engine = $this->engine;

		$params = [
			new Customer(),
			'items' => [
				['description' => 'Item one', 'qty' => 1, 'priceNetto' => 1, 'vatPerc' => 10],
				['description' => 'Item two', 'qty' => 2, 'priceNetto' => 2, 'vatPerc' => 20],
				['description' => 'Item three', 'qty' => 3, 'priceNetto' => 3, 'vatPerc' => 30],
				['description' => 'Item four', 'qty' => 4, 'priceNetto' => 4, 'vatPerc' => 40],
			],
			'var_symbol' => '0012345678', // test special case: numeric starting with zero should cast to string
		];

		$html = <<<HTML
<h2>Rendering invoice items for customer #{{customer.id}} on {{ today }}  ...</h2>
<table class="table">

{{ SET total = 0 }}
{{ SET subtotal = 0 }}

{{for item in items}}

	{{ SET subtotal = item.qty * item.priceNetto * (100 + item.vatPerc) / 100 }}

	{{ if loop.first }}
		{{ SET note = "FIRST LOOP" }}
	{{ elseif loop.last }}
		{{ SET note = "LAST LOOP" }}
	{{ elseif loop.index > 1 }}
		{{ SET note = "NOT FIRST NOR LAST LOOP" }}
	{{ endif }}

	<tr>
		<td> #{{ loop.index }} - {{ note }}</td>
		<td> {{ item.description }} </td>
		<td> {{ item.qty }} </td>
		<td> {{ item.priceNetto | round(2) }} </td>
		<td> {{ item.vatPerc | round(2) }}% </td>
		<td> {{ subtotal | round(2) }} &euro; </td>
	</tr>

	{{ SET total = total + subtotal }}

{{ elsefor }}
	<tr><td> EMPTY ITEMS! </td></tr>
{{ endfor }}

</table>

{{ if total > 0 }}
<p>Amount due: <b> {{ total | round(2) }} Eur </b></p>
{{ else }}
<p>DO NOT PAY!</p>
{{ endif }}

{{ if var_symbol }} Invoice VS: {{ var_symbol }} {{ endif }}
HTML;

		$engine->setLogErrors(true);
		$resultHtml = $engine->render($html, $params);

		$this->assertTrue(
			   false !== strpos($resultHtml, 'customer #123') // translated model attributes
			&& false === strpos($resultHtml, '{{') // all placeholders translated
			&& false === strpos($resultHtml, 'EMPTY ITEMS')
			&& false === strpos($resultHtml, 'DO NOT PAY')
			&& false !== strpos($resultHtml, '#4 - LAST LOOP') // properly detected last item
			&& false !== strpos($resultHtml, '<p>Amount due: <b> 40.00 Eur </b></p>') // expected total due amount
			&& false !== strpos($resultHtml, $params['var_symbol']) // test leading zero in numeric param
		);

		// output via loading extern file "_invoice.html"
		// alias @templates is defined in app config - see mockApp() bellow
		// this will also include invoice header from separate partial template "_invoice_header.html"
		$params += [
			// dot and array notations - both are valid
			'supplier.name' => 'My Supplier, Ltd.',
			'supplier.address' => 'Supplier Rd. 123<br> London<br> NW4 E44',
			/*
			'supplier' => [
				'name' => 'My Supplier, Ltd.',
				'address' => 'Supplier Rd. 123, London, NW4 E44',
			],
			*/
		];
		$resultHtml = $engine->render('@templates/_invoice.html', $params);

		$this->assertTrue(
			   false !== strpos($resultHtml, 'via file template') // specific string
			&& false !== strpos($resultHtml, 'My Supplier, Ltd.') // imported header
			&& false !== strpos($resultHtml, 'customer #123') // translated model attributes
			&& false === strpos($resultHtml, '{{') // all placeholders translated
			&& false === strpos($resultHtml, 'EMPTY ITEMS') // all placeholders translated
			&& false !== strpos($resultHtml, '#4 - LAST LOOP') // properly detected last item
			&& false !== strpos($resultHtml, '<b>40.00 &euro;</b>') // expected total due amount
		);

		$params['items'] = [];
		$resultHtml = $engine->render($html, $params);

		$this->assertTrue(
			   false === strpos($resultHtml, '{{') // all placeholders translated
			&& false !== strpos($resultHtml, 'EMPTY ITEMS')
		);

		unset($params['items']);
		$resultHtml = $engine->render($html, $params);

		$this->assertTrue(
			   false === strpos($resultHtml, '{{') // all placeholders translated
			&& false !== strpos($resultHtml, 'EMPTY ITEMS')
		);
	}

	/**
	* Initiate Yii console application
	* @param array $config Optional app config
	* @return \yii\console\Application
	*/
	protected static function mockApp($config = [])
	{
		if(!$config || !is_array($config)){
			// default minimal app config
			$config = [
				// required minimum setup
				'id' => 'MockApp',
				'basePath' => dirname(__DIR__),

				// optional, set if we want to access templating engine
				// as a component e.g. Yii::$app->template
				'components' => [
					'template' => [
						'class' => 'lubosdz\yii2\TemplateEngine',
					]
				],

				'aliases' => [
					'@templates' => __DIR__
				],
			];
		}

		return new \yii\console\Application($config);
	}
}

/**
* @var Mock up customer object
*/
class Customer extends \yii\base\Model
{
	public $id = 123;
	public $name = 'John Doe';
	public $email = 'john@doe.com';
	public $address = 'Customer Rd. 321<br> Cambridge<br> EW9 X40';
	public $datetime_created;

	public function init()
	{
		$this->datetime_created = date('Y-m-d H:i:s');
	}
}
