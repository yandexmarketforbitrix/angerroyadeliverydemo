<?php
namespace Sale\Handlers\Delivery;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main;
use Bitrix\Sale as SaleModule;

Loc::loadMessages(__FILE__);

require_once __DIR__ . '/classes/polygon.php';

class AngerroYadeliveryDemoHandler extends SaleModule\Delivery\Services\Base
{
	const MODULE_ID = 'angerro.yadelivery';

	public static function getClassTitle()
	{
		return static::getLangMessage('TITLE');
	}

	public static function getClassDescription()
	{
		return static::getLangMessage('DESCRIPTION');
	}

	private static function getLangMessage($key)
	{
		return Loc::getMessage('ANGERRO_YADELIVERY_DEMO_HANDLER_' . $key) ?: $key;
	}

	protected function getConfigStructure()
	{
		return [
			'MAIN' => [
				'TITLE' => static::getLangMessage('CONFIG'),
				'ITEMS' => [
					'MAP_ID' => [
						'TYPE' => 'ENUM',
						'NAME' => static::getLangMessage('MAP_ID'),
						'REQUIRED' => true,
						'OPTIONS' => $this->getConfigStructureMapIdOptions(),
					],
					'PROPERTY_LAT' => [
						'TYPE' => 'STRING',
						'NAME' => static::getLangMessage('PROPERTY_LAT'),
						'REQUIRED' => true,
					],
					'PROPERTY_LON' => [
						'TYPE' => 'STRING',
						'NAME' => static::getLangMessage('PROPERTY_LON'),
						'REQUIRED' => true,
					],
					'DEFAULT_PRICE' => [
						'TYPE' => 'NUMBER',
						'NAME' => static::getLangMessage('DEFAULT_PRICE'),
					],
					'DEFAULT_PERIOD_FROM' => [
						'TYPE' => 'NUMBER',
						'NAME' => static::getLangMessage('DEFAULT_PERIOD_FROM'),
					],
					'DEFAULT_PERIOD_TO' => [
						'TYPE' => 'NUMBER',
						'NAME' => static::getLangMessage('DEFAULT_PERIOD_TO'),
					],
				],
			],
		];
	}

	protected function getConfigStructureMapIdOptions()
	{
		if (!Main\Loader::includeModule(static::MODULE_ID)) { return []; }

		$connection = Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$result = [];

		$query = $connection->query(sprintf(
			'SELECT %s, %s FROM %s',
			$sqlHelper->quote('id'),
			$sqlHelper->quote('name'),
			$sqlHelper->quote('angerro_yadelivery')
		));

		while ($row = $query->fetch())
		{
			$result[$row['id']] = sprintf('[%s] %s', $row['id'], $row['name']);
		}

		return $result;
	}

	protected function getPropertyCode($type)
	{
		$configKey = 'PROPERTY_' . $type;
		$result = $this->getConfigStringValue($configKey);

		if ($result === null)
		{
			throw new Main\ObjectPropertyException(sprintf('config %s', $configKey));
		}

		return $result;
	}

	protected function getMapId()
	{
		$value = $this->getConfigNumberValue('MAP_ID');

		if ($value === null)
		{
			throw new Main\ObjectPropertyException('config MAP_ID');
		}

		return $value;
	}

	protected function getDefaultPrice()
	{
		return $this->getConfigNumberValue('DEFAULT_PRICE');
	}

	protected function getDefaultPeriod()
	{
		return [
			'FROM' => $this->getConfigNumberValue('DEFAULT_PERIOD_FROM'),
			'TO' => $this->getConfigNumberValue('DEFAULT_PERIOD_TO'),
		];
	}

	protected function getConfigNumberValue($key, $chain = 'MAIN')
	{
		$result = null;

		if (isset($this->config[$chain][$key]))
		{
			$value = preg_replace('/\s+/', '', $this->config[$chain][$key]);

			if ($value !== '' && is_numeric($value))
			{
				$result = (int)$value;
			}
		}

		return $result;
	}

	protected function getConfigStringValue($key, $chain = 'MAIN')
	{
		$result = null;

		if (isset($this->config[$chain][$key]))
		{
			$value = trim($this->config[$chain][$key]);

			if ($value !== '')
			{
				$result = $value;
			}
		}

		return $result;
	}

	public function isCompatible(SaleModule\Shipment $shipment)
	{
		try
		{
			$this->includeModule();

			$order = $this->getShipmentOrder($shipment);
			$this->getOrderCoordinates($order);

			$result = true;
		}
		catch (Main\SystemException $exception)
		{
			$result = false;
		}

		return $result;
	}

	protected function calculateConcrete(SaleModule\Shipment $shipment)
	{
		$result = new SaleModule\Delivery\CalculationResult();

		try
		{
			$this->includeModule();

			$order = $this->getShipmentOrder($shipment);
			$coordinates = $this->getOrderCoordinates($order);
			$mapId = $this->getMapId();
			$zones = $this->getZones($mapId);
			$matchedZone = $this->getMatchedZone($zones, $coordinates);

			$price = $matchedZone['PRICE'] !== null ? $matchedZone['PRICE'] : $this->getDefaultPrice();
			$zonePeriod = $matchedZone['PERIOD'] !== null ? $matchedZone['PERIOD'] : $this->getDefaultPeriod();

			if ($price === null)
			{
				throw new Main\ObjectException(sprintf('zone %s price is undefined', $matchedZone['TITLE']));
			}

			$result->setDeliveryPrice($price);

			if (isset($zonePeriod['FROM']))
			{
				$result->setPeriodFrom($zonePeriod['FROM']);
			}

			if (isset($zonePeriod['TO']))
			{
				$result->setPeriodTo($zonePeriod['TO']);
			}
		}
		catch (Main\SystemException $exception)
		{
			$result->addError(new Main\Error(
				$exception->getMessage(),
				$exception->getCode()
			));
		}

		return $result;
	}

	protected function includeModule()
	{
		if (!Main\Loader::includeModule(static::MODULE_ID))
		{
			throw new Main\SystemException(sprintf(
				'module %s required',
				static::MODULE_ID
			));
		}
	}

	protected function getShipmentOrder(SaleModule\Shipment $shipment)
	{
		/** @var SaleModule\ShipmentCollection $shipmentCollection*/
		$shipmentCollection = $shipment->getCollection();

		if ($shipmentCollection === null)
		{
			throw new Main\NotSupportedException('standalone shipment not supported');
		}

		$order = $shipmentCollection->getOrder();

		if ($order === null)
		{
			throw new Main\NotSupportedException('shipment without order not supported');
		}

		return $order;
	}

	/**
	 * @param SaleModule\Order $order
	 *
	 * @return array{LAT: float, LON: float}
	 * @throws Main\ArgumentException
	 * @throws Main\NotImplementedException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	protected function getOrderCoordinates(SaleModule\Order $order)
	{
		$propertyCollection = $order->getPropertyCollection();
		$properties = $this->getOrderPropertiesByCodes($propertyCollection, [
			$this->getPropertyCode('LAT'),
			$this->getPropertyCode('LON'),
		]);
		$propertyValues = $this->collectOrderPropertiesValue($properties);

		return array_values($propertyValues);
	}

	protected function getOrderPropertiesByCodes(SaleModule\PropertyValueCollection $propertyCollection, array $codes)
	{
		$result = [];

		foreach ($codes as $code)
		{
			$result[$code] = $this->getOrderPropertyByCode($propertyCollection, $code);
		}

		return $result;
	}

	protected function getOrderPropertyByCode(SaleModule\PropertyValueCollection $propertyCollection, $code)
	{
		$result = null;

		/** @var SaleModule\PropertyValue $propertyValue */
		foreach ($propertyCollection as $propertyValue)
		{
			$property = $propertyValue->getProperty();

			if (isset($property['CODE']) && $property['CODE'] === $code)
			{
				$result = $propertyValue;
				break;
			}
		}

		if ($result === null)
		{
			throw new Main\ArgumentException(sprintf('cant find order property with code %s', $code));
		}

		return $result;
	}

	/**
	 * @param SaleModule\PropertyValue[] $properties
	 *
	 * @return float[]
	 */
	protected function collectOrderPropertiesValue(array $properties)
	{
		$result = [];

		foreach ($properties as $code => $property)
		{
			$propertyValue = $property->getValue();

			if (empty($propertyValue))
			{
				throw new Main\ArgumentException(sprintf('order property %s value is empty', $code));
			}

			if (!is_scalar($propertyValue))
			{
				throw new Main\ArgumentException(sprintf('order property %s value must be scalar', $code));
			}

			if (!is_numeric($propertyValue))
			{
				throw new Main\ArgumentException(sprintf('order property %s value must be numeric', $code));
			}

			$result[$code] = (float)$propertyValue;
		}

		return $result;
	}

	protected function getZones($mapId)
	{
		$connection = Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$query = $connection->query(sprintf(
			'SELECT * FROM %s WHERE %s = %s',
			$sqlHelper->quote('angerro_yadelivery'),
			$sqlHelper->quote('id'),
			$mapId
		));
		$row = $query->fetch();

		if ($row === false)
		{
			throw new Main\ObjectNotFoundException(sprintf('cant find zone with id equals %s', $mapId));
		}

		return $this->parseZoneData($row['data']);
	}

	protected function parseZoneData($dataEncoded)
	{
		$data = Main\Web\Json::decode($dataEncoded);

		if (!isset($data[0]['delivery_areas']) || !is_array($data[0]['delivery_areas']))
		{
			throw new Main\ArgumentNullException('delivery_areas');
		}

		return $this->makeZoneFromDeliveryAreas($data[0]['delivery_areas']);
	}

	protected function makeZoneFromDeliveryAreas($deliveryAreas)
	{
		$result = [];

		foreach ($deliveryAreas as $deliveryArea)
		{
			if (empty($deliveryArea['area_coordinates'][0])) { continue; }
			if (empty($deliveryArea['settings']['title'])) { continue; }

			$price = $this->extractZoneDeliveryAreaPrice($deliveryArea['settings']['title']);
			$period = $this->extractZoneDeliveryAreaPeriod($deliveryArea['settings']['title']);

			$result[] = [
				'TITLE' => $deliveryArea['settings']['title'],
				'PRICE' => $price,
				'PERIOD' => $period,
				'POLYGON' => $deliveryArea['area_coordinates'][0],
			];
		}

		return $result;
	}

	protected function extractZoneDeliveryAreaPrice($title)
	{
		$result = null;

		if (preg_match('/\bprice:\s*([\d\s]+)/', $title, $matches))
		{
			$result = (int)preg_replace('/\s/', '', $matches[1]);
		}

		return $result;
	}

	protected function extractZoneDeliveryAreaPeriod($title)
	{
		$result = null;

		if (preg_match('/\bperiod:\s*(\d+)(?:-(\d+))?/', $title, $matches))
		{
			$result = [
				'FROM' => (int)$matches[1],
				'TO' => isset($matches[2]) ? (int)$matches[2] : null,
			];
		}

		return $result;
	}

	protected function getMatchedZone($zones, $coordinates)
	{
		$result = null;

		foreach ($zones as $zone)
		{
			if (!$this->isMatchZone($zone['POLYGON'], $coordinates)) { continue; }

			$result = $zone;
			break;
		}

		if ($result === null)
		{
			throw new Main\ObjectException('cant find matched zone');
		}

		return $result;
	}

	protected function isMatchZone($polygon, $point)
	{
		$searcher = new AngerroYadeliveryDemo\Polygon($polygon);

		return $searcher->pip($point[0], $point[1]);
	}
}