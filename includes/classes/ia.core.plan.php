<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2014 Intelliants, LLC <http://www.intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link http://www.subrion.org/
 *
 ******************************************************************************/

class iaPlan extends abstractCore
{
	const SECONDS_PER_DAY = 86400;

	const SPONSORED = 'sponsored';
	const SPONSORED_DATE_START = 'sponsored_start';
	const SPONSORED_DATE_END = 'sponsored_end';
	const SPONSORED_PLAN_ID = 'sponsored_plan_id';

	const METHOD_POST_PAYMENT = 'postPayment';
	const METHOD_CANCEL_PLAN = 'planCancelling';

	const UNIT_HOUR = 'hour';
	const UNIT_DAY = 'day';
	const UNIT_WEEK = 'week';
	const UNIT_MONTH = 'month';
	const UNIT_YEAR = 'year';

	protected static $_table = 'payment_plans';
	protected $_tableSubscriptions = 'payment_subscriptions';

	protected $_item;
	protected $_plans = array();


	public function getTableSubscriptions()
	{
		return $this->_tableSubscriptions;
	}

	/**
	 * Payment pre-processing actions
	 *
	 * @param $itemName item name
	 * @param $itemData current item data, id field is mandatory
	 * @param $planId plan id to be paid for
	 * @param null $title transaction title
	 * @param int $amount amount to be paid
	 * @param string $returnUrl post payment return url
	 *
	 * @return bool|string
	 */
	public function prePayment($itemName, $itemData, $planId, $returnUrl = IA_URL)
	{
		if (!$planId || !isset($this->_plans[$planId]))
		{
			return $returnUrl;
		}

		if (empty($itemData))
		{
			return false;
		}

		$cost = $this->_plans[$planId]['cost'];

		if ('members' != $itemName && !empty($itemData[self::SPONSORED]))
		{
			/*
			$rdbmsDate = $this->iaDb->one('CURDATE()');
			$daysLeft = strtotime($itemData[self::SPONSORED_DATE_END]) - strtotime($rdbmsDate);
			$daysLeft = $daysLeft > 0 ? $daysLeft / 86400 : 0;
			$cost -= round($daysLeft * ($itemData['cost'] / $itemData['days']), 2);
			*/
		}

		$iaTransaction = $this->iaCore->factory('transaction');
		$paymentId = $iaTransaction->createInvoice(null, $cost, $itemName, $itemData, $returnUrl, $planId, true);

		return IA_URL . 'pay' . IA_URL_DELIMITER . $paymentId . IA_URL_DELIMITER;
	}

	/**
	 * Return plan information
	 *
	 * @param integer $planId plan id
	 *
	 * @return null|array
	 */
	public function getById($planId)
	{
		$plan = null;

		if (!is_array($planId))
		{
			$plan = $this->iaDb->row_bind(iaDb::ALL_COLUMNS_SELECTION, '`status` = :status AND `id` = :id', array('status' => iaCore::STATUS_ACTIVE, 'id' => (int)$planId), self::getTable());
			if ($plan)
			{
				$plan['title'] = iaLanguage::get('plan_title_' . $plan['id']);
				$plan['description'] = iaLanguage::get('plan_description_' . $plan['id']);
			}
		}

		return $plan;
	}

	/**
	 * Returns an array of available plans
	 *
	 * @param null $itemName option item name
	 *
	 * @return array
	 */
	public function getPlans($itemName = null)
	{
		if (is_null($itemName))
		{
			return isset($this->_item) ? $this->_item : array();
		}

		if (!isset($this->_item) || ($this->_item != $itemName))
		{
			if ($plans = $this->iaDb->all(array('id', 'duration', 'unit', 'cost', 'data'), "`item` = '{$itemName}' AND `status` = 'active' ORDER BY `order` ASC", null, null, self::getTable()))
			{
				foreach ($plans as $plan)
				{
					$plan['data'] = unserialize($plan['data']);
					$plan['fields'] = isset($plan['data']['fields']) ? implode(',', $plan['data']['fields']) : '';

					$this->_plans[$plan['id']] = $plan;
				}
			}

			$this->_item = $itemName;
		}

		return $this->_plans;
	}

	/**
	 * Write funds off from member balance.
	 *
	 * @param array $transactionData data about transaction
	 *
	 * @return bool true on success
	 */
	public function extractFunds(array $transactionData)
	{
		if (!iaUsers::hasIdentity())
		{
			return false;
		}

		$iaUsers = $this->iaCore->factory('users');
		$iaTransaction = $this->iaCore->factory('transaction');

		$userInfo = $iaUsers->getInfo(iaUsers::getIdentity()->id);

		$remainingBalance = $userInfo['funds'] - $transactionData['amount'];
		if ($remainingBalance >= 0)
		{
			$result = (bool)$iaUsers->update(array('funds' => $remainingBalance), iaDb::convertIds(iaUsers::getIdentity()->id));

			if ($result)
			{
				iaUsers::reloadIdentity();

				$updatedValues = array(
					'status' => iaTransaction::PASSED,
					'gateway' => iaTransaction::TRANSACTION_MEMBER_BALANCE,
					'reference_id' => date('YmdHis'),
					'member_id' => iaUsers::getIdentity()->id
				);

				$iaTransaction->update($updatedValues, $transactionData['id']);
			}

			return $result;
		}

		return false;
	}


	public function setUnpaid($itemName, $itemId) // unassigns paid plan
	{
		// first, try to update DB record
		$tableName = $this->iaCore->factory('item')->getItemTable($itemName);
		$stmt = iaDb::convertIds($itemId);

		$entry = $this->iaDb->row(array(self::SPONSORED, self::SPONSORED_PLAN_ID), $stmt, $tableName);
		if (empty($entry) || !$entry[self::SPONSORED])
		{
			return false;
		}

		$values = array(
			self::SPONSORED => 0,
			self::SPONSORED_PLAN_ID => 0,
			self::SPONSORED_DATE_START => null,
			self::SPONSORED_DATE_END => null
		);

		$plan = $this->getById($entry[self::SPONSORED_PLAN_ID]);

		if (!empty($plan['expiration_status']))
		{
			$values['status'] = $plan['expiration_status'];
		}

		$result = $this->iaDb->update($values, $stmt, null, $tableName);

		// then, try to call class' helper
		$this->_runClassMethod($itemName, self::METHOD_CANCEL_PLAN, array($itemId));

		// TODO: #1804 (the respective email should be sent here)

		return $result;
	}

	public function setPaid($transaction) // updates item's sponsored record
	{
		if (!is_array($transaction))
		{
			return false;
		}

		$result = false;

		$item = $transaction['item'];
		$plan = $this->getById($transaction['plan_id']);

		if ($plan && $item && !empty($transaction['item_id']))
		{
			list($dateStarted, $dateFinished) = $this->_calculateDates($plan['duration'], $plan['unit']);

			$values = array(
				self::SPONSORED => 1,
				self::SPONSORED_PLAN_ID => $transaction['plan_id'],
				self::SPONSORED_DATE_START => $dateStarted,
				self::SPONSORED_DATE_END => $dateFinished,
				'status' => iaCore::STATUS_ACTIVE
			);

			$iaItem = $this->iaCore->factory('item');
			$result = $this->iaDb->update($values, iaDb::convertIds($transaction['item_id']), null, $iaItem->getItemTable($item));
		}

		// perform item specific actions
		$this->_runClassMethod($item, self::METHOD_POST_PAYMENT, array($plan, $transaction));

		return $result;
	}

	public function createSubscription()
	{
		$entry = array();

		return $this->iaDb->insert($entry, null, $this->getTableSubscriptions());
	}

	private function _calculateDates($duration, $unit)
	{
		switch ($unit)
		{
			case self::UNIT_HOUR:
			case self::UNIT_DAY:
			case self::UNIT_WEEK: // use pre-calculated data
				$unitDurationInSeconds = array(self::UNIT_HOUR => 3600, self::UNIT_DAY => 86400, self::UNIT_WEEK => 604800);
				$base = $unitDurationInSeconds[$unit];

				break;

			case self::UNIT_MONTH:
				$days = date('t');
				$base = self::SECONDS_PER_DAY * $days;

				break;

			case self::UNIT_YEAR:
				$date = getdate();
				$days = date('z', mktime(0, 0, 0, 12, 31, $date['year'])) + 1;
				$base = self::SECONDS_PER_DAY * $days;
		}

		$dateStarted = time();
		$dateFinished = $dateStarted + ($base * $duration);

		return array(
			date(iaDb::DATETIME_FORMAT, $dateStarted),
			date(iaDb::DATETIME_FORMAT, $dateFinished)
		);
	}

	private function _runClassMethod($itemName, $method, array $args = array())
	{
		$iaItem = $this->iaCore->factory('item');

		$className = ucfirst(substr($itemName, 0, -1));
		$itemClassInstance = ($itemName == 'members')
			? $this->iaCore->factory('users')
			: $this->iaCore->factoryPackage($className, $iaItem->getPackageByItem($itemName));

		if ($itemClassInstance && method_exists($itemClassInstance, $method))
		{
			return call_user_func_array(array($itemClassInstance, $method), $args);
		}

		return false;
	}
}