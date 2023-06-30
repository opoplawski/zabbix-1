<?php
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * @var CView $this
 * @var array $data
 */

$this->addJsFile('class.tagfilteritem.js');
$this->includeJsFile('item.list.js.php', $data);

$filter = new CPartial('item.list.filter', [
	'action' => $data['action'],
	'filter_data' => $data['filter_data'],
	'subfilter' => $data['subfilter'],
	'context' => $data['context']
]);

$form = (new CForm())
	->setId('item-list')
	->setName('item_list')
	->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('item'), 'item-csrf-token')
	->addVar('context', $data['context'])
	->addVar('hostid', $data['hostid'] != 0 ? $data['hostid'] : null);

$list_url = (new CUrl())
	->setArgument('context', $data['context'])
	->setArgument('action', $data['action'])
	->getUrl();

$header = [
	(new CColHeader(
		(new CCheckBox('all_items'))->onClick("checkAll('item_list', 'all_items', 'itemids');")
	))->addClass(ZBX_STYLE_CELL_WIDTH),
	'',
	($data['hostid'] != 0)
		? null
		: ($data['context'] === 'host' ? _('Host') : _('Template')),
	make_sorting_header(_('Name'), 'name', $data['sort'], $data['sortorder'], $list_url),
	_('Triggers'),
	make_sorting_header(_('Key'), 'key_', $data['sort'], $data['sortorder'], $list_url),
	make_sorting_header(_('Interval'), 'delay', $data['sort'], $data['sortorder'], $list_url),
	make_sorting_header(_('History'), 'history', $data['sort'], $data['sortorder'], $list_url),
	make_sorting_header(_('Trends'), 'trends', $data['sort'], $data['sortorder'], $list_url),
	make_sorting_header(_('Type'), 'type', $data['sort'], $data['sortorder'], $list_url),
	make_sorting_header(_('Status'), 'status', $data['sort'], $data['sortorder'], $list_url),
	_('Tags'),
	($data['context'] === 'host') ? _('Info') : null
];

$item_list = (new CTableInfo())->setHeader($header);
$now_ts = time();
$update_interval_parser = new CUpdateIntervalParser(['usermacros' => true]);

foreach ($data['items'] as $item) {
	// Description
	$description = makeItemTemplatePrefix($item['itemid'], $data['parent_templates'], ZBX_FLAG_DISCOVERY_NORMAL,
		$data['allowed_ui_conf_templates']
	);

	if ($item['discoveryRule']) {
		$description[] = (new CLink($item['discoveryRule']['name'],
			(new CUrl('disc_prototypes.php'))
				->setArgument('parent_discoveryid', $item['discoveryRule']['itemid'])
				->setArgument('context', $data['context'])
		))
			->addClass(ZBX_STYLE_LINK_ALT)
			->addClass(ZBX_STYLE_ORANGE);
		$description[] = NAME_DELIMITER;
	}

	if ($item['type'] == ITEM_TYPE_DEPENDENT) {
		if ($item['master_item']['type'] == ITEM_TYPE_HTTPTEST) {
			$description[] = $item['master_item']['name'];
		}
		else {
			$description[] = (new CLink($item['master_item']['name'],
				(new CUrl('items.php'))
					->setArgument('form', 'update')
					->setArgument('hostid', $item['hostid'])
					->setArgument('itemid', $item['master_item']['itemid'])
					->setArgument('context', $data['context'])
			))
				->addClass(ZBX_STYLE_LINK_ALT)
				->addClass(ZBX_STYLE_TEAL);
		}

		$description[] = NAME_DELIMITER;
	}

	$description[] = new CLink($item['name'],
		(new CUrl('items.php'))
			->setArgument('form', 'update')
			->setArgument('hostid', $item['hostid'])
			->setArgument('itemid', $item['itemid'])
			->setArgument('context', $data['context'])
	);

	// Trigger information
	$hint_table = (new CTableInfo())->setHeader([_('Severity'), _('Name'), _('Expression'), _('Status')]);

	foreach ($item['triggers'] as $trigger) {
		$trigger = $data['triggers'][$trigger['triggerid']];
		$hint_table->addRow([
			CSeverityHelper::makeSeverityCell((int) $trigger['priority']),
			[
				makeTriggerTemplatePrefix($trigger['triggerid'], $data['trigger_parent_templates'],
					ZBX_FLAG_DISCOVERY_NORMAL, $data['allowed_ui_conf_templates']
				),
				new CLink(
					$trigger['description'],
					(new CUrl('triggers.php'))
						->setArgument('form', 'update')
						->setArgument('hostid', array_column($trigger['hosts'], 'hostid'))
						->setArgument('triggerid', $trigger['triggerid'])
						->setArgument('context', $data['context'])
						->setArgument('backurl', $list_url)
				)
			],
			(new CDiv(
				$trigger['recovery_mode'] == ZBX_RECOVERY_MODE_RECOVERY_EXPRESSION
					? [
						_('Problem'), ': ', $trigger['expression'], BR(),
						_('Recovery'), ': ', $trigger['recovery_expression']
					]
					: $trigger['expression']
			))->addClass(ZBX_STYLE_WORDWRAP),
			(new CSpan(triggerIndicator($trigger['status'], $trigger['state'])))
				->addClass(triggerIndicatorStyle($trigger['status'], $trigger['state']))
		]);
	}

	// Interval
	if (in_array($item['type'], [ITEM_TYPE_TRAPPER, ITEM_TYPE_SNMPTRAP, ITEM_TYPE_DEPENDENT])
			|| ($item['type'] == ITEM_TYPE_ZABBIX_ACTIVE && strpos($item['key_'], 'mqtt.get') !== 0)) {
		$item['delay'] = '';
	}
	elseif ($update_interval_parser->parse($item['delay']) == CParser::PARSE_SUCCESS) {
		$item['delay'] = $update_interval_parser->getDelay();
	}

	// Trends
	if (in_array($item['value_type'], [ITEM_VALUE_TYPE_STR, ITEM_VALUE_TYPE_LOG, ITEM_VALUE_TYPE_TEXT])) {
		$item['trends'] = '';
	}

	// Info
	$info_cell = null;

	if ($data['context'] === 'host') {
		$info_cell = [];

		if ($item['status'] == ITEM_STATUS_ACTIVE && $item['error'] !== '') {
			$info_cell[] = makeErrorIcon($item['error']);
		}

		// discovered item lifetime indicator
		if ($item['flags'] == ZBX_FLAG_DISCOVERY_CREATED && $item['itemDiscovery']['ts_delete'] != 0) {
			$info_cell[] = getItemLifetimeIndicator($now_ts, (int) $item['itemDiscovery']['ts_delete']);
		}

		$info_cell = makeInformationList($info_cell);
	}

	$can_execute = in_array($item['type'], $data['check_now_types']) && $item['status'] == ITEM_STATUS_ACTIVE
		&& $item['hosts'][0]['status'] == HOST_STATUS_MONITORED;
	$row = [
		(new CCheckBox('itemids['.$item['itemid'].']', $item['itemid']))
			->setAttribute('data-actions', $can_execute ? 'execute' : null),
		(new CButtonIcon(ZBX_ICON_MORE))
			->setMenuPopup(
				CMenuPopupHelper::getItem([
					'itemid' => $item['itemid'],
					'context' => $data['context'],
					'backurl' => $list_url
				])
			),
		$data['hostid'] != 0 ? null : $item['hosts'][0]['host'],
		(new CCol($description))->addClass(ZBX_STYLE_WORDBREAK),
		$item['triggers']
			? [
				(new CLinkAction(_('Triggers')))->setHint($hint_table),
				CViewHelper::showNum($hint_table->getNumRows())
			]
			: '',
		(new CDiv($item['key_']))->addClass(ZBX_STYLE_WORDWRAP),
		$item['delay'],
		$item['history'],
		$item['trends'],
		item_type2str($item['type']),
		(new CLink(itemIndicator($item['status'], $item['state'])))
			->addClass(ZBX_STYLE_LINK_ACTION)
			->addClass(itemIndicatorStyle($item['status'], $item['state']))
			->addClass($item['status'] == ITEM_STATUS_DISABLED ? 'js-enable-item' : 'js-disable-item')
			->setAttribute('data-itemid', $item['itemid']),
		$data['tags'][$item['itemid']],
		$info_cell
	];

	$item_list->addRow($row);
}

$form->addItem([$item_list, $data['paging']]);

$buttons = [
	'item.massenable' => [
		'content' => (new CSimpleButton(_('Enable')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-massenable-item')
			->addClass('js-no-chkbxrange')
	],
	'item.massdisable' => [
		'content' => (new CSimpleButton(_('Disable')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-massdisable-item')
			->addClass('js-no-chkbxrange')
	],
	'item.massexecute' => [
		'content' => (new CSimpleButton(_('Execute now')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-massexecute-item')
			->addClass('js-no-chkbxrange')
			->addClass('js-execute-now')
			->setAttribute('data-required', 'execute')
	],
	'item.massclearhistory' => [
		'content' => (new CSimpleButton(_('Clear history')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-massclearhistory-item')
			->addClass('js-no-chkbxrange')
	],
	'item.masscopy' => [
		'content' => (new CSimpleButton(_('Copy')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-masscopy-item')
			->addClass('js-no-chkbxrange')
	],
	'item.massupdate' => [
		'content' => (new CSimpleButton(_('Mass update')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-massupdate-item')
			->addClass('js-no-chkbxrange')
	],
	'item.massdelete' => [
		'content' => (new CSimpleButton(_('Delete')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-massdelete-item')
			->addClass('js-no-chkbxrange')
	]
];

if ($data['context'] === 'template') {
	unset($buttons['item.massexecute'], $buttons['item.massclearhistory']);
}

$form->addItem(new CActionButtonList('action', 'itemids', $buttons, 'item'));

(new CHtmlPage())
	->setTitle(_('Items'))
	->setDocUrl(CDocHelper::getUrl($data['context'] === 'host'
		? CDocHelper::DATA_COLLECTION_HOST_ITEM_LIST
		: CDocHelper::DATA_COLLECTION_TEMPLATE_ITEM_LIST
	))
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					$data['hostid'] != 0
						? (new CSimpleButton(_('Create item')))->addClass('js-create-item')
						: (new CSimpleButton(
							$data['context'] === 'host'
								? _('Create item (select host first)')
								: _('Create item (select template first)')
						))->setEnabled(false)
				)
		))->setAttribute('aria-label', _('Content controls'))
	)
	->setNavigation(
		$data['hostid'] != 0
			? getHostNavigation('items', $data['hostid'])
			: null
	)
	->addItem($filter)
	->addItem($form)
	->show();

$confirm_messages = [
	'item.enable' => [_('Enable selected item?'), _('Enable selected items?')],
	'item.disable' => [_('Disable selected item?'), _('Disable selected items?')],
	'item.clear' => $data['context'] === 'host' && !CHousekeepingHelper::get(CHousekeepingHelper::COMPRESSION_STATUS)
		? [_('Delete history of selected item?'), _('Delete history of selected items?')]
		: [],
	'item.delete' => [_('Delete selected item?'), _('Delete selected items?')]
];

(new CScriptTag('
	view.init('.json_encode([
		'confirm_messages' => $confirm_messages
	]).');
'))
	->setOnDocumentReady()
	->show();
