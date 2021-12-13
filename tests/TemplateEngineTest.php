<?php
/**
* Tests for Yii2 templating engine
*/

use lubosdz\yii2\TemplateEngine;
use PHPUnit\Framework\TestCase;

class TemplateEngineTest extends TestCase
{
	protected $engine;

	/**
	 * Run before each test is started
	 */
	protected function setUp()
	{
		// initiate static property Yii::$app
		self::mockApp();

		// init templating engine - either via namespace or as Yii component
		$this->engine = new TemplateEngine;
		//$this->engine = Yii::$app->template; // works too

		// turn off logging since we dont have database setup for tests
		$this->engine->setLogErrors(false);
	}

	/**
	* Test replacing placeholders with scalars and models (ActiveRecord, ActiveForm)
	*/
	public function testModelsAndScalars()
	{
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
	}

	/**
	* Test dynamic directoves - callable named functions accepting arguments
	*/
	public function testDynamicDirectives()
	{
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
		$engine = $this->engine;

		$params = [
			'condition' => 5,
			new Customer(),
		];

		$html = <<<HTML
{{ if condition == 5 && customer.id > 0 }}
	MATCH #1 - CONDITION IS EXACTLY 5 for customer ID. {{ customer.id }} created on {{ customer.created_datetime | date }}.
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
	}

	/**
	* Test loop FOR and SET
	*/
	public function test_ForAndSet()
	{
		$engine = $this->engine;

		$params = [
			new Customer(),
			'items' => [
				['description' => 'Item one', 'qty' => 1, 'priceNetto' => 1, 'vatPerc' => 10],
				['description' => 'Item two', 'qty' => 2, 'priceNetto' => 2, 'vatPerc' => 20],
				['description' => 'Item three', 'qty' => 3, 'priceNetto' => 3, 'vatPerc' => 30],
				['description' => 'Item four', 'qty' => 4, 'priceNetto' => 4, 'vatPerc' => 40],
			]
		];

		$html = <<<HTML
<h2>Rendering invoice items for customer #{{customer.id}} on {{ today }}  ...</h2>
<table class="table">

{{ for item in items }}

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
	EMPTY ITEM #{{ loop.index }} !!
{{ endfor }}

</table>

<p>Amount due: <b> {{ total | round(2) }} Eur </b></p>
HTML;

		$resultHtml = $engine->render($html, $params);

		$this->assertTrue(
			   false !== strpos($resultHtml, 'customer #123') // translated model attributes
			&& false === strpos($resultHtml, '{{') // all placeholders translated
			&& false !== strpos($resultHtml, '#4 - LAST LOOP') // properly detected last item
			&& false !== strpos($resultHtml, '<p>Amount due: <b> 40.00 Eur </b></p>') // expected total due amount
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
				]
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
	public $datetime_created;

	public function init()
	{
		$this->datetime_created = date('Y-m-d H:i:s');
	}
}
