<?php

return [
    'bulk_delete'		=> '批量删除确认',
    'bulk_restore'      => '确认批量恢复资产', 
  'bulk_delete_help'	=> '请在此确认将批量删除的资产。在删除后，资产可以恢复，但一切当前的用户关联将会丢失。',
  'bulk_restore_help'	=> '查看下面的资产进行批量恢复。一旦恢复，这些资产将不会与以前分配给的任何用户相关联。',
  'bulk_delete_warn'	=> '即将删除 :asset_count 项资产',
  'bulk_restore_warn'	=> '您即将恢复 :asset_count 项资产。',
    'bulk_update'		=> '批量更新',
    'bulk_update_help'	=> '该表格允许你同时修改多项资产。请仅填写需要修改的字段，留空的字段不会做任何修改。 ',
    'bulk_update_warn'	=> '您将要编辑单个资产的属性。 |您将要编辑:asset_count个资产的属性。',
    'bulk_update_with_custom_field' => '请注意，资产是 :asset_model_count 种不同类型的型号。',
    'bulk_update_model_prefix' => '', 
    'bulk_update_custom_field_unique' => '这是一个唯一的字段，不能进行批量编辑。',
    'checkedout_to'		=> '借出至',
    'checkout_date'		=> '借出日期',
    'checkin_date'		=> '登记日期',
    'checkout_to'		=> '借出至',
    'cost'				=> '采购价格',
    'create'			=> '创建资产',
    'date'				=> '购买时间',
    'depreciation'	    => '折旧',
    'depreciates_on'	=> '折旧于',
    'default_location'	=> '默认位置',
    'default_location_phone' => '默认位置电话',
    'eol_date'			=> '产品寿命日期',
    'eol_rate'			=> '产品寿命等级',
    'expected_checkin'  => '预计归还日期',
    'expires'			=> '到期',
    'fully_depreciated'	=> '足折旧',
    'help_checkout'		=> '如果你希望立即分配该资产，请从上面的状态列表中选择“可部署”。 ',
    'mac_address'		=> 'MAC地址',
    'manufacturer'		=> '生产厂家',
    'model'				=> '型号',
    'months'			=> '月数',
    'name'				=> '资产名称',
    'notes'				=> '备注',
    'order'				=> '订单号',
    'qr'				=> '二维码',
    'requestable'		=> '用户可能申请了该资产',
    'redirect_to_all'   => '返回到所有 :type',
    'redirect_to_type'   => '跳转到 :type',
    'redirect_to_checked_out_to'   => '跳转到借用者',
    'select_statustype'	=> '选择状态类型',
    'serial'			=> '序列号',
    'status'			=> '状态',
    'tag'				=> '资产标签',
    'update'			=> '更新资产',
    'warranty'			=> '质保',
        'warranty_expires'		=> '保修期已过',
    'years'				=> '年',
    'asset_location' => '更新资产位置',
    'asset_location_update_default_current' => '更新默认位置与实际位置',
    'asset_location_update_default' => '仅更新默认位置',
    'asset_location_update_actual' => '仅更新实际位置',
    'asset_not_deployable' => '该资产状态为不可部署。无法借出此资产。',
    'asset_not_deployable_checkin' => '此资产状态为不可部署。使用此状态标签将会归还资产。',
    'asset_deployable' => '此资产可以借出。',
    'processing_spinner' => '处理中...（对于大型文件可能需要一些时间）',
    'optional_infos'  => '可选信息',
    'order_details'   => '订单相关信息',
    'calc_eol'    => '如果将 EOL 日期设为零，则根据购买日期和EOL率自动计算EOL。',
];
