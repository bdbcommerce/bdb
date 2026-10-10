<?php

/**
 * Starter articles 1–10 (Auto ID News, Tax Policy). Evergreen: no decree numbers,
 * deadlines or statistics, because the AI assistant repeats these to customers.
 * Each item: [category en-slug, title, excerpt, content (HTML)], every text in uz/ru/en.
 */
return [
    ['auto-id-news',
        [
            'uz' => 'Avtoidentifikatsiya (AIDC) nima va u Oʻzbekiston biznesiga nima beradi',
            'ru' => 'Что такое автоидентификация (AIDC) и что она даёт бизнесу в Узбекистане',
            'en' => 'What automatic identification (AIDC) is and what it gives businesses in Uzbekistan',
        ],
        [
            'uz' => 'Shtrix-kodlar, RFID va maʼlumot yigʻish terminallari qoʻlda kiritishni kamaytiradi, xatolarni kamaytiradi va hisobni tezlashtiradi.',
            'ru' => 'Штрихкоды, RFID и терминалы сбора данных сокращают ручной ввод, уменьшают ошибки и ускоряют учёт.',
            'en' => 'Barcodes, RFID and data collection terminals cut manual entry, reduce errors and speed up record keeping.',
        ],
        [
            'uz' => '<p>Avtomatik identifikatsiya va maʼlumot yigʻish (AIDC) — tovarlar, hujjatlar va aktivlar haqidagi maʼlumotni qoʻlda yozmasdan, skaner yoki oʻquvchi yordamida tizimga kiritish texnologiyalari. Bularga shtrix-kodlar, 2D kodlar (masalan, DataMatrix va QR), RFID metkalari hamda mobil terminallar kiradi.</p><p>Oʻzbekistonda raqamli markirovka, elektron hisob-fakturalar va onlayn kassalar keng tarqalgani sari tovar haqidagi aniq maʼlumot biznes uchun majburiyatga aylanmoqda. AIDC kassada, omborda va ishlab chiqarishda bu maʼlumotni tez va xatosiz yigʻishga yordam beradi.</p><p>Asosiy foydalari: inventarizatsiya tezlashadi, qayta saralash va yoʻqotishlar kamayadi, qoldiqlar real vaqtda koʻrinadi. BDB uskunani tanlash, oʻrnatish va hisob tizimingizga ulashda yordam beradi.</p>',
            'ru' => '<p>Автоматическая идентификация и сбор данных (AIDC) — это технологии, которые позволяют вносить информацию о товарах, документах и активах в систему с помощью сканера или считывателя, а не вручную. Сюда относятся штрихкоды, 2D-коды (например, DataMatrix и QR), RFID-метки и мобильные терминалы.</p><p>В Узбекистане по мере распространения цифровой маркировки, электронных счетов-фактур и онлайн-касс точные данные о товаре становятся для бизнеса обязательными. AIDC помогает собирать эти данные быстро и без ошибок — на кассе, на складе и на производстве.</p><p>Главные выгоды: инвентаризация проходит быстрее, пересортица и потери сокращаются, остатки видны в реальном времени. BDB помогает подобрать оборудование, установить его и подключить к вашей учётной системе.</p>',
            'en' => '<p>Automatic identification and data capture (AIDC) covers the technologies that put information about goods, documents and assets into a system with a scanner or reader instead of by hand: barcodes, 2D codes such as DataMatrix and QR, RFID tags and mobile terminals.</p><p>In Uzbekistan, as digital product marking, electronic invoices and online cash registers spread, accurate product data is becoming a requirement rather than a nice-to-have. AIDC captures that data quickly and without typos at the checkout, in the warehouse and on the production line.</p><p>The main gains are faster stocktakes, fewer mix-ups and losses, and stock levels you can see in real time. BDB helps choose the equipment, install it and connect it to your accounting system.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => '1D va 2D shtrix-kodlar: qaysi birini tanlash kerak',
            'ru' => '1D и 2D штрихкоды: какой выбрать',
            'en' => '1D vs 2D barcodes: which one to choose',
        ],
        [
            'uz' => 'Chiziqli kodlar oddiy va arzon, 2D kodlar esa koʻproq maʼlumot sigʻdiradi va markirovka uchun zarur.',
            'ru' => 'Линейные коды просты и дёшевы, 2D-коды вмещают больше данных и нужны для маркировки.',
            'en' => 'Linear codes are simple and cheap; 2D codes hold far more data and are needed for marking.',
        ],
        [
            'uz' => '<p>1D (chiziqli) shtrix-kodlar — masalan, EAN-13 yoki Code 128 — odatda faqat tovar raqamini saqlaydi. Ular chakana savdo va omborda koʻp yillardan beri ishlatiladi va deyarli har qanday skaner ularni oʻqiydi.</p><p>2D kodlar — DataMatrix, QR, PDF417 — kichik maydonda ancha koʻp maʼlumot saqlaydi: seriya raqami, partiya, muddat va himoya belgisi. Shuning uchun Asl Belgi kabi raqamli markirovka tizimlari DataMatrix koddan foydalanadi.</p><p>Tanlash qoidasi oddiy: agar sizga faqat tovarni aniqlash kerak boʻlsa, 1D yetarli. Agar har bir donani alohida kuzatish yoki markirovka talab qilinsa, 2D kodlarni oʻqiy oladigan skaner (imager) kerak. Bugun koʻp xaridorlar ikkalasini ham oʻqiydigan 2D skanerni tanlaydi.</p>',
            'ru' => '<p>1D (линейные) штрихкоды — например, EAN-13 или Code 128 — обычно хранят только номер товара. Их много лет используют в рознице и на складах, и их читает практически любой сканер.</p><p>2D-коды — DataMatrix, QR, PDF417 — вмещают на маленькой площади гораздо больше данных: серийный номер, партию, срок годности и защитный элемент. Поэтому системы цифровой маркировки, такие как Асл Белги, используют код DataMatrix.</p><p>Правило выбора простое: если нужно только опознать товар, хватит 1D. Если требуется отслеживать каждую единицу или работать с маркировкой, нужен сканер-имиджер, читающий 2D-коды. Сегодня многие сразу выбирают 2D-сканер — он читает оба типа.</p>',
            'en' => '<p>1D (linear) barcodes such as EAN-13 or Code 128 usually hold just a product number. Retail and warehouses have used them for decades, and almost any scanner reads them.</p><p>2D codes such as DataMatrix, QR and PDF417 hold much more data in a small area: a serial number, batch, expiry date and a security element. That is why digital marking systems like Asl Belgi use DataMatrix.</p><p>The rule of thumb: if you only need to identify the product, 1D is enough. If you must track every single item or handle marked goods, you need an imager that reads 2D codes. Today many buyers simply choose a 2D scanner, because it reads both.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => 'Kassa uchun shtrix-kod skanerini qanday tanlash kerak',
            'ru' => 'Как выбрать сканер штрихкодов для кассы',
            'en' => 'How to choose a barcode scanner for the checkout',
        ],
        [
            'uz' => 'Qoʻl skaneri yoki stol skaneri, simli yoki simsiz — kassa oqimi va markirovkalangan tovarlarga qarab tanlang.',
            'ru' => 'Ручной или стационарный, проводной или беспроводной — выбирайте по потоку покупателей и наличию маркированных товаров.',
            'en' => 'Handheld or presentation, corded or wireless: choose by customer flow and whether you sell marked goods.',
        ],
        [
            'uz' => '<p>Avvalo qanday kodlarni oʻqishingiz kerakligini aniqlang. Markirovkalangan tovarlar sotilsa, DataMatrix kodni ishonchli oʻqiydigan 2D skaner kerak; ekrandagi QR kodlar uchun ham 2D skaner zarur.</p><p>Keyin ish oqimiga qarang. Xaridorlar koʻp boʻlgan supermarketlarda stol (presentation) skaneri tezroq ishlaydi — tovarni shunchaki oyna oldidan oʻtkazish kifoya. Kichik doʻkonlar va katta tovarlar uchun qoʻl skaneri qulayroq; simsiz model kassadan uzoqdagi ogʻir tovarlarni skanerlashga imkon beradi.</p><p>Shuningdek, kassa dasturi bilan ulanish turi (USB, klaviatura emulyatsiyasi yoki COM), korpus mustahkamligi va kafolat shartlariga eʼtibor bering. BDB mutaxassislari doʻkoningizga mos modelni tavsiya qiladi.</p>',
            'ru' => '<p>Сначала определите, какие коды нужно читать. Если вы продаёте маркированные товары, нужен 2D-сканер, уверенно читающий DataMatrix; для QR-кодов с экрана телефона тоже нужен 2D-сканер.</p><p>Затем посмотрите на поток покупателей. В супермаркетах с большим потоком быстрее работает стационарный (презентационный) сканер — товар достаточно провести перед окном. Небольшим магазинам и для крупных товаров удобнее ручной сканер; беспроводная модель позволяет сканировать тяжёлые товары вдали от кассы.</p><p>Обратите внимание и на способ подключения к кассовой программе (USB, эмуляция клавиатуры или COM), прочность корпуса и условия гарантии. Специалисты BDB подберут модель под ваш магазин.</p>',
            'en' => '<p>Start with the codes you need to read. If you sell marked goods you need a 2D scanner that reads DataMatrix reliably, and a 2D scanner is also needed for QR codes shown on phone screens.</p><p>Then look at customer flow. In busy supermarkets a presentation scanner is faster: the cashier just passes the item in front of the window. Small shops and bulky goods suit a handheld scanner, and a wireless model lets staff scan heavy items away from the till.</p><p>Also check how it connects to your POS software (USB, keyboard emulation or COM), how rugged the housing is and the warranty terms. BDB specialists will recommend a model for your shop.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => 'Yorliq printerlari: termo va termotransfer bosma farqi',
            'ru' => 'Принтеры этикеток: прямая термопечать или термотрансфер',
            'en' => 'Label printers: direct thermal vs thermal transfer',
        ],
        [
            'uz' => 'Qisqa muddatli yorliqlar uchun termo bosma, uzoq saqlanadigan va markirovka yorliqlari uchun termotransfer.',
            'ru' => 'Для краткосрочных этикеток — прямая термопечать, для долговечных и маркировочных — термотрансфер.',
            'en' => 'Direct thermal for short-lived labels, thermal transfer for durable and marking labels.',
        ],
        [
            'uz' => '<p>Termo (direct thermal) printer maxsus termoqogʻozga lentasiz bosadi. Bu arzon va oddiy, lekin yorliq quyosh, issiqlik va vaqt taʼsirida xiralashadi. U yetkazib berish yorliqlari, cheklar va qisqa muddatli narx yorliqlari uchun mos.</p><p>Termotransfer printer rangli lenta (ribbon) orqali bosadi. Tasvir ancha chidamli boʻladi, qogʻoz, sintetik va plyonka materiallarga bosish mumkin. Uzoq saqlanadigan mahsulotlar, aktivlarni hisobga olish va markirovka kodlari uchun odatda termotransfer tanlanadi, chunki kod butun muddat davomida oʻqilishi kerak.</p><p>Shuningdek, bosma hajmi (stol, sanoat yoki mobil printer), ruxsat (dpi) va kod oʻlchamini hisobga oling: kichik DataMatrix kodlar uchun yuqori ruxsat foydali. BDB printer, lenta va yorliqni bitta toʻplam sifatida tanlashga yordam beradi.</p>',
            'ru' => '<p>Принтер прямой термопечати печатает на специальной термобумаге без красящей ленты. Это дёшево и просто, но этикетка выцветает от солнца, тепла и со временем. Такой вариант подходит для транспортных этикеток, чеков и ценников с коротким сроком жизни.</p><p>Термотрансферный принтер печатает через красящую ленту (риббон). Изображение получается гораздо стойче, и можно печатать на бумаге, синтетике и плёнке. Для товаров с долгим сроком хранения, учёта основных средств и маркировочных кодов обычно выбирают термотрансфер: код должен читаться весь срок.</p><p>Учитывайте также объём печати (настольный, промышленный или мобильный принтер), разрешение (dpi) и размер кода: для мелких DataMatrix полезно высокое разрешение. BDB поможет подобрать принтер, риббон и этикетку как единый комплект.</p>',
            'en' => '<p>A direct thermal printer prints on heat-sensitive paper without a ribbon. It is cheap and simple, but the label fades with sunlight, heat and time, so it suits shipping labels, receipts and short-lived price tags.</p><p>A thermal transfer printer prints through an ink ribbon. The image is far more durable, and you can print on paper, synthetic and film materials. For long-shelf-life products, asset tracking and marking codes, thermal transfer is the usual choice, because the code must stay readable for the product\'s whole life.</p><p>Also consider print volume (desktop, industrial or mobile printer), resolution (dpi) and code size: small DataMatrix codes benefit from higher resolution. BDB helps choose the printer, ribbon and labels as one matched set.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => 'Ombor uchun maʼlumot yigʻish terminallari (TSD)',
            'ru' => 'Терминалы сбора данных (ТСД) для склада',
            'en' => 'Mobile data collection terminals for the warehouse',
        ],
        [
            'uz' => 'Mobil terminal qabul qilish, terish va inventarizatsiyani qogʻozsiz va real vaqtda bajarishga imkon beradi.',
            'ru' => 'Мобильный терминал позволяет вести приёмку, комплектацию и инвентаризацию без бумаги и в реальном времени.',
            'en' => 'A mobile terminal lets staff receive, pick and count stock without paper and in real time.',
        ],
        [
            'uz' => '<p>Maʼlumot yigʻish terminali (TSD) — oʻrnatilgan skaner, ekran va simsiz aloqaga ega mustahkam qoʻl kompyuteri. Ombor xodimi tovarni skanerlaydi va maʼlumot darhol hisob tizimiga tushadi.</p><p>Terminal bilan qabul qilishda hujjat va haqiqiy tovar avtomatik solishtiriladi, buyurtma terishda xodim nima va qayerdan olishni ekranda koʻradi, inventarizatsiya esa varaqalar va qayta kiritishsiz oʻtadi. Markirovkalangan tovarlar bilan ishlaganda terminal har bir DataMatrix kodni oʻqiydi va qayd etadi.</p><p>Tanlashda operatsion tizim, skanerlash masofasi, batareya quvvati, changga va tushishga chidamlilik hamda hisob dasturingiz bilan mosligiga qarang. BDB terminallarni yetkazib beradi va ularni tizimingizga ulashga yordam beradi.</p>',
            'ru' => '<p>Терминал сбора данных (ТСД) — это защищённый карманный компьютер со встроенным сканером, экраном и беспроводной связью. Кладовщик сканирует товар, и данные сразу попадают в учётную систему.</p><p>С терминалом при приёмке документ и фактический товар сверяются автоматически, при комплектации сотрудник видит на экране, что и откуда взять, а инвентаризация проходит без бумажных листов и повторного ввода. При работе с маркированными товарами терминал считывает и фиксирует каждый код DataMatrix.</p><p>При выборе смотрите на операционную систему, дальность сканирования, ёмкость батареи, защиту от пыли и падений и совместимость с вашей учётной программой. BDB поставляет терминалы и помогает подключить их к вашей системе.</p>',
            'en' => '<p>A mobile data collection terminal is a rugged handheld computer with a built-in scanner, screen and wireless connection. A warehouse worker scans an item and the data goes straight into the accounting system.</p><p>With a terminal, receiving automatically checks the delivery against the document, picking shows staff what to take and from where, and stocktakes need no paper lists or re-keying. When handling marked goods, the terminal reads and records every DataMatrix code.</p><p>When choosing one, look at the operating system, scan range, battery life, resistance to dust and drops, and compatibility with your accounting software. BDB supplies terminals and helps connect them to your system.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => 'RFID: shtrix-koddan qachon foydaliroq',
            'ru' => 'RFID: когда он выгоднее штрихкода',
            'en' => 'RFID: when it pays off over barcodes',
        ],
        [
            'uz' => 'RFID koʻplab metkalarni koʻrish chizigʻisiz bir vaqtda oʻqiydi — katta hajmlar va tez-tez inventarizatsiya uchun qulay.',
            'ru' => 'RFID считывает много меток сразу и без прямой видимости — удобно при больших объёмах и частой инвентаризации.',
            'en' => 'RFID reads many tags at once without line of sight, which suits large volumes and frequent stocktakes.',
        ],
        [
            'uz' => '<p>RFID (radiochastotali identifikatsiya) tovar yoki aktivga yopishtirilgan metkadagi maʼlumotni radio orqali oʻqiydi. Shtrix-koddan farqli ravishda, metkani koʻrish shart emas va oʻquvchi bir necha soniyada yuzlab metkalarni qayd eta oladi.</p><p>RFID ayniqsa quyidagi hollarda oʻzini oqlaydi: kiyim-kechak va poyabzal doʻkonlarida tez-tez inventarizatsiya, qaytariladigan idishlar va konteynerlarni kuzatish, asbob-uskunalar va kutubxona fondlarini hisobga olish, darvoza orqali yuklarni avtomatik qayd etish.</p><p>Metka shtrix-koddan qimmatroq, shuning uchun loyihani kichik sinovdan boshlash va tejalgan vaqtni hisoblash maqsadga muvofiq. Metall va suyuqliklar oʻqishga taʼsir qiladi, shuning uchun metka turini mutaxassis bilan tanlang. BDB RFID yechimini sinab koʻrish va joriy etishda yordam beradi.</p>',
            'ru' => '<p>RFID (радиочастотная идентификация) считывает данные с метки на товаре или активе по радио. В отличие от штрихкода, метку не нужно видеть, а считыватель за несколько секунд фиксирует сотни меток.</p><p>RFID особенно окупается в таких задачах: частая инвентаризация в магазинах одежды и обуви, учёт оборотной тары и контейнеров, учёт инструмента и библиотечных фондов, автоматическая фиксация грузов при проходе через ворота.</p><p>Метка дороже штрихкода, поэтому проект разумно начинать с небольшого пилота и считать сэкономленное время. Металл и жидкости влияют на считывание, поэтому тип метки лучше подбирать со специалистом. BDB помогает провести пилот и внедрить RFID-решение.</p>',
            'en' => '<p>RFID (radio frequency identification) reads data from a tag on a product or asset by radio. Unlike a barcode, the tag does not need to be visible, and a reader can record hundreds of tags in a few seconds.</p><p>RFID pays off most in tasks such as frequent stocktakes in clothing and footwear stores, tracking returnable crates and containers, managing tools and library collections, and recording goods automatically as they pass through a gate.</p><p>A tag costs more than a printed barcode, so it is sensible to start with a small pilot and measure the time saved. Metal and liquids affect reading, so choose the tag type with a specialist. BDB helps run the pilot and roll out an RFID solution.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => 'Shtrix-kodlar bilan ombor inventarizatsiyasi: bosqichma-bosqich',
            'ru' => 'Инвентаризация склада со штрихкодами: пошагово',
            'en' => 'Warehouse stocktaking with barcodes, step by step',
        ],
        [
            'uz' => 'Tayyorgarlik, joylarni belgilash, skanerlash va farqlarni tahlil qilish — tez va aniq inventarizatsiya uchun reja.',
            'ru' => 'Подготовка, адресное хранение, сканирование и разбор расхождений — план быстрой и точной инвентаризации.',
            'en' => 'Preparation, location labels, scanning and reviewing differences: a plan for fast, accurate counts.',
        ],
        [
            'uz' => '<p><b>1. Tayyorgarlik.</b> Barcha tovarlarda oʻqiladigan shtrix-kod borligini tekshiring, yorligʻi yoʻqlariga yangi yorliq bosing. Hisob tizimida tovar kartochkalari va kodlar mosligini tekshiring.</p><p><b>2. Joylarni belgilash.</b> Javon va yacheykalarga manzil shtrix-kodlarini yopishtiring. Shunda xodim avval joyni, keyin undagi tovarni skanerlaydi va qoldiq aniq joyga bogʻlanadi.</p><p><b>3. Skanerlash.</b> Maʼlumot yigʻish terminallari bilan zonalar boʻyicha ishlang; maʼlumot real vaqtda yoki zona tugagach tizimga yuklanadi.</p><p><b>4. Farqlarni tahlil qilish.</b> Tizim kamomad va ortiqchalarni koʻrsatadi; shubhali pozitsiyalarni qayta sanang. Shundan keyin muntazam qisman inventarizatsiyaga oʻtish oson boʻladi. BDB terminallar, printerlar va sozlashda yordam beradi.</p>',
            'ru' => '<p><b>1. Подготовка.</b> Убедитесь, что на всех товарах есть читаемый штрихкод, и напечатайте этикетки для товаров без него. Проверьте в учётной системе соответствие карточек товаров и кодов.</p><p><b>2. Адресное хранение.</b> Наклейте штрихкоды адресов на стеллажи и ячейки. Тогда сотрудник сканирует сначала ячейку, затем товар в ней, и остаток привязывается к точному месту.</p><p><b>3. Сканирование.</b> Работайте по зонам с терминалами сбора данных; данные загружаются в систему в реальном времени или по завершении зоны.</p><p><b>4. Разбор расхождений.</b> Система покажет недостачи и излишки; спорные позиции пересчитайте. После этого легко перейти к регулярным частичным инвентаризациям. BDB поможет с терминалами, принтерами и настройкой.</p>',
            'en' => '<p><b>1. Prepare.</b> Make sure every item has a readable barcode and print labels for those that do not. Check that product records and codes match in your accounting system.</p><p><b>2. Label locations.</b> Put location barcodes on racks and bins. Staff then scan the location first and the goods in it, so stock is tied to an exact place.</p><p><b>3. Scan.</b> Work zone by zone with data collection terminals; data uploads to the system in real time or when a zone is finished.</p><p><b>4. Review differences.</b> The system shows shortages and surpluses; recount the doubtful lines. After that it is easy to move to regular cycle counts. BDB can help with terminals, printers and setup.</p>',
        ]],

    ['auto-id-news',
        [
            'uz' => 'Tovar uchun shtrix-kod raqamini qanday olish mumkin (GS1)',
            'ru' => 'Как получить номер штрихкода для товара (GS1)',
            'en' => 'How to get barcode numbers for your products (GS1)',
        ],
        [
            'uz' => 'Chakana savdoga chiqadigan tovarlar uchun EAN-13 raqamlari GS1 tizimi orqali beriladi — oʻzingiz oʻylab topgan raqamlar mos kelmaydi.',
            'ru' => 'Номера EAN-13 для товаров в рознице выдаются в системе GS1 — придуманные самостоятельно номера не подходят.',
            'en' => 'EAN-13 numbers for retail products are issued through the GS1 system; numbers you make up yourself will not do.',
        ],
        [
            'uz' => '<p>Doʻkon javonlaridagi tovarlardagi EAN-13 shtrix-kod shunchaki tasodifiy raqam emas. U GS1 xalqaro tizimida roʻyxatdan oʻtgan kompaniya prefiksini oʻz ichiga oladi va tovarni butun dunyo boʻyicha yagona qiladi. Tarmoq doʻkonlari va marketpleyslar odatda aynan GS1 raqamlarini talab qiladi.</p><p>Raqamlarni olish uchun ishlab chiqaruvchi yoki brend egasi oʻz mamlakatidagi milliy GS1 tashkilotiga aʼzo boʻladi va har bir mahsulotga raqam beradi. Shartlar va narxlarni toʻgʻridan-toʻgʻri GS1 tashkilotidan aniqlang, chunki ular oʻzgarishi mumkin.</p><p>Raqamlar olingach, ularni toʻgʻri oʻlcham va kontrast bilan bosish muhim, aks holda kassada kod oʻqilmasligi mumkin. BDB yorliq printerlari, yorliqlar va bosilgan kodlarni tekshirish boʻyicha yordam beradi.</p>',
            'ru' => '<p>Штрихкод EAN-13 на товаре в магазине — не случайный номер. Он содержит префикс компании, зарегистрированный в международной системе GS1, и делает товар уникальным во всём мире. Торговые сети и маркетплейсы, как правило, требуют именно номера GS1.</p><p>Чтобы получить номера, производитель или владелец бренда вступает в национальную организацию GS1 своей страны и присваивает номер каждому продукту. Условия и стоимость уточняйте непосредственно в организации GS1 — они могут меняться.</p><p>После получения номеров важно печатать коды с правильным размером и контрастом, иначе они могут не читаться на кассе. BDB поможет с принтерами этикеток, этикетками и проверкой качества напечатанных кодов.</p>',
            'en' => '<p>The EAN-13 barcode on a product in a shop is not a random number. It contains a company prefix registered in the international GS1 system, which makes the product unique worldwide. Retail chains and marketplaces usually require GS1 numbers.</p><p>To get numbers, the manufacturer or brand owner joins the national GS1 member organization in their country and assigns a number to each product. Check the terms and fees with the GS1 organization directly, as they can change.</p><p>Once you have the numbers, print the codes at the right size and contrast, or they may fail to scan at the checkout. BDB can help with label printers, labels and checking print quality.</p>',
        ]],

    ['tax-policy',
        [
            'uz' => 'Onlayn kassalar va fiskal cheklar: biznes nimani bilishi kerak',
            'ru' => 'Онлайн-кассы и фискальные чеки: что важно знать бизнесу',
            'en' => 'Online cash registers and fiscal receipts: what businesses should know',
        ],
        [
            'uz' => 'Onlayn kassa har bir sotuv maʼlumotini soliq organlariga yuboradi. Toʻgʻri tovar maʼlumotlari va skaner ishni tezlashtiradi.',
            'ru' => 'Онлайн-касса передаёт данные о каждой продаже в налоговые органы. Точные данные о товаре и сканер ускоряют работу.',
            'en' => 'An online register sends every sale to the tax authorities. Accurate product data and a scanner make it faster.',
        ],
        [
            'uz' => '<p>Oʻzbekistonda chakana savdo va xizmatlar sohasida onlayn nazorat-kassa texnikasi va virtual kassalar qoʻllaniladi. Har bir chek fiskal maʼlumot sifatida soliq organlariga uzatiladi, xaridor esa chekni tekshirishi mumkin.</p><p>Biznes uchun bu shuni anglatadiki, chekdagi tovar nomi, narxi va kodlari toʻgʻri boʻlishi kerak. Qoʻlda kiritishda xato oson qilinadi; shtrix-kod skaneri tovarni maʼlumotlar bazasidan oladi va kassir faqat sonini tasdiqlaydi. Markirovkalangan tovarlarni sotishda DataMatrix kod ham skanerlanadi.</p><p>Talablar va kassa turlariga oid qoidalar oʻzgarib turadi, shuning uchun amaldagi talablarni soliq organlari yoki kassa dasturi yetkazib beruvchisidan aniqlang. BDB kassaga mos skanerlar va chek printerlarini tanlashda yordam beradi.</p>',
            'ru' => '<p>В Узбекистане в рознице и сфере услуг применяются онлайн контрольно-кассовая техника и виртуальные кассы. Каждый чек передаётся в налоговые органы как фискальные данные, а покупатель может проверить чек.</p><p>Для бизнеса это значит, что наименование товара, цена и коды в чеке должны быть точными. При ручном вводе легко ошибиться; сканер штрихкодов берёт товар из базы, и кассиру остаётся подтвердить количество. При продаже маркированных товаров сканируется и код DataMatrix.</p><p>Требования и правила по типам касс меняются, поэтому актуальные условия уточняйте в налоговых органах или у поставщика кассовой программы. BDB поможет подобрать сканеры и принтеры чеков для вашей кассы.</p>',
            'en' => '<p>In Uzbekistan, retail and service businesses use online cash registers and virtual cash registers. Each receipt is sent to the tax authorities as fiscal data, and the customer can verify it.</p><p>For a business this means product names, prices and codes on the receipt must be accurate. Typing them by hand invites mistakes; a barcode scanner pulls the product from the database and the cashier only confirms the quantity. When selling marked goods, the DataMatrix code is scanned too.</p><p>Requirements and rules for register types change over time, so confirm the current ones with the tax authorities or your POS software provider. BDB can help choose scanners and receipt printers for your till.</p>',
        ]],

    ['tax-policy',
        [
            'uz' => 'Elektron hisob-fakturalar va MXIK kodlari: tovar maʼlumotlarini tartibga keltirish',
            'ru' => 'Электронные счета-фактуры и коды ИКПУ: наводим порядок в данных о товарах',
            'en' => 'Electronic invoices and product catalogue codes: getting product data in order',
        ],
        [
            'uz' => 'Elektron hujjatlarda har bir tovar milliy katalog kodi bilan koʻrsatiladi. Kodlar tartibda boʻlsa, hujjat almashinuvi tezlashadi.',
            'ru' => 'В электронных документах каждый товар указывается с кодом национального каталога. Порядок в кодах ускоряет документооборот.',
            'en' => 'Electronic documents list each product with a national catalogue code. Tidy codes make document exchange faster.',
        ],
        [
            'uz' => '<p>Oʻzbekistonda kompaniyalar oʻrtasidagi savdo elektron hisob-fakturalar orqali rasmiylashtiriladi. Hujjatlarda va cheklarda tovarlar mahsulot va xizmatlarning milliy katalogi (MXIK) kodi bilan koʻrsatiladi, markirovkalangan tovarlarda esa markirovka kodlari ham hisobga olinishi mumkin.</p><p>Amalda koʻp xatolar tovar kartochkalaridagi tartibsizlikdan kelib chiqadi: bitta tovar turli nomlar bilan, notoʻgʻri kod yoki oʻlchov birligi bilan kiritilgan. Shtrix-kod va katalog kodini har bir tovar kartochkasiga bogʻlab qoʻyish qabul qilishda, sotuvda va hujjatlarda bir xil maʼlumot boʻlishini taʼminlaydi.</p><p>Aniq qoidalar va kodlarni tanlash boʻyicha rasmiy manbalar va buxgalteringiz bilan maslahatlashing. BDB omborda va kassada tovarni skaner orqali aniqlash jarayonini sozlashda yordam beradi.</p>',
            'ru' => '<p>В Узбекистане торговля между компаниями оформляется электронными счетами-фактурами. В документах и чеках товары указываются с кодом национального каталога товаров и услуг (ИКПУ), а для маркированных товаров могут учитываться и коды маркировки.</p><p>На практике многие ошибки происходят из-за беспорядка в карточках товаров: один и тот же товар заведён под разными названиями, с неверным кодом или единицей измерения. Если привязать к каждой карточке штрихкод и код каталога, данные будут одинаковыми при приёмке, продаже и в документах.</p><p>Точные правила и выбор кодов уточняйте по официальным источникам и с вашим бухгалтером. BDB поможет настроить идентификацию товара сканером на складе и на кассе.</p>',
            'en' => '<p>In Uzbekistan, trade between companies is documented with electronic invoices. Documents and receipts list products with a code from the national catalogue of goods and services, and for marked goods the marking codes may be recorded as well.</p><p>In practice many errors come from messy product records: the same item entered under different names, with the wrong code or unit of measure. Linking each product record to its barcode and catalogue code keeps the data identical at receiving, at sale and in documents.</p><p>Check the exact rules and the choice of codes with official sources and your accountant. BDB helps set up scanner-based product identification in the warehouse and at the checkout.</p>',
        ]],
];
