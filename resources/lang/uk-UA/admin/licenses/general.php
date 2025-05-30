<?php

return array(
    'about_licenses_title'      => 'Про ліцензії',
    'about_licenses'            => 'Ліцензії використовуються для відстеження програмних забезпечень. У них є певну кількість місць, які можуть бути перевірені особистому',
    'checkin'  					=> 'Потрібно перейти між ліцензіями',
    'checkout_history'  		=> 'Історія видачі',
    'checkout'  				=> 'Чекайте місце ліцензії',
    'edit'  					=> 'Редагувати ліцензію',
    'filetype_info'				=> 'Дозволені типи файлів - png, gif, jpg, jpeg, doc, docx, pdf, txt, zip і rar.',
    'clone'  					=> 'Клонувати ліцензію',
    'history_for'  				=> 'Історія для ',
    'in_out'  					=> 'В/З',
    'info'  					=> 'Інформація про ліцензію',
    'license_seats'  			=> 'Місця ліцензії',
    'seat'  					=> 'Місце',
    'seat_count'  				=> 'Місце :count',
    'seats'  					=> 'Кількість місць',
    'software_licenses'  		=> 'Ліцензії на програмне забезпечення',
    'user'  					=> 'Користувач',
    'view'  					=> 'Переглянути ліцензію',
    'delete_disabled'           => 'Ця ліцензія ще не може бути видалена, оскільки деякі місця все ще перевіряються.',
    'bulk'                      =>
        [
            'checkin_all'           => [
                'button'            => 'Прийняти всі місця',
                'modal'             => 'Ця дія перевірятиме всі місця в одному місці. | Ця дія буде перевіряти всі :checkedout_seats_count місць для цієї ліцензії.',
                'enabled_tooltip'   => 'Прийняти ВСІ місця для цієї ліцензії від користувачів і активів',
                'disabled_tooltip'  => 'Це вимкнено, тому що наразі немає місць',
                'disabled_tooltip_reassignable'  => 'Це вимкнено, оскільки ліцензія не є розумною',
                'success'           => 'Ліцензія успішно перевірена! | Всі ліцензії успішно перевірені!',
                'log_msg'           => 'Зареєстровано через масове повернення ліцензій у графічному інтерфейсі ліцензій',
            ],

            'checkout_all'              => [
                'button'                => 'Видати всі місця',
                'modal'                 => 'Ця дія перевірить одне місце для першого доступного користувача. | Ця дія перевірить всі :available_seats_count місць з першими доступними користувачами. Користувача вважається доступним для цього місця, якщо ця ліцензія вже не перевірена їм, і автоматично призначення властивості ліцензії увімкнено на їхній обліковий запис користувача.',
                'enabled_tooltip'   => 'Викидати всі місця (або стільки, скільки доступно) всім користувачам',
                'disabled_tooltip'  => 'Це вимкнено, оскільки відсутні місця',
                'success'           => 'Ліцензія успішно перевірена! | :count ліцензії успішно перевірені!',
                'error_no_seats'    => 'На цій ліцензії не залишилось місць.',
                'warn_not_enough_seats'    => ':count користувачі були призначені для цієї ліцензії, але ми закінчили доступні місця в ліцензії.',
                'warn_no_avail_users'    => 'Нічого робити. Немає користувачів, які не мають цієї ліцензії, призначеної для них.',
                'log_msg'           => 'Перевірено через масову ліцензію з GUI',


            ],
    ],

    'below_threshold' => 'Залишилось :remaining_count місць для цієї ліцензії з мінімальною кількістю :min_amt. Ви можете розглянути можливість придбання більше місць.',
    'below_threshold_short' => 'Цей товар знаходиться нижче мінімальної необхідної кількості.',
);
