# integration_with_bitrix24
Реализация интеграции заказов и форм через init.php

**Структура файлов должна быть такой:**
```
/local/
├── classes/
│   └── FormBitrix24Handler.php  (новый класс)
├── logs/
│   └── bitrix24.log  (создастся автоматически)
└── php_interface/
    └── init.php  (добавить код выше)
