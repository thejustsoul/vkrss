# Generating RSS Feed for opened or closed wall of user or community (group, public page or event page) on vk.com
# Генерация RSS-ленты открытой или закрытой стены пользователя или сообщества (группы, публичной страницы или мероприятия) во Вконтакте.


## Возможности:
* Получение RSS-ленты открытых стен (не требующих авторизации): извлечение описания из разных частей (включая вложенные) и построение заголовков на основе описания.
* Получение RSS-ленты закрытых стен при наличии токена с правами оффлайн-доступа, привязанного к профилю, которому открыт доступ к такой стене. [Ниже описан один из способов получения токена](#Как-получить-бессрочный-токен-для-доступа-к-стенам-которые-доступны-своему-профилю).
* Получение произвольного количества записей со стены.
* Получение записей, опубликованных от кого угодно, от имени сообщества/владельца страницы или ото всех, кроме сообщества/владельца страницы.
* Фильтрация записей по соответствию и/или несоответствию регулярному выражению в стиле PCRE.
* При желании исключение записей в сообществе, помеченных как реклама.
* Извлечение хеш-тегов в качестве RSS-категорий.
* При желании HTML-форматирование всех видов ссылок, изображений, переносов строк.
* Допустимо использование HTTP, HTTPS, SOCKS4, SOCKS4A или SOCKS5 прокси-сервера для запросов.


## Требования
* PHP>=5.2.2 (в т.ч. 5.3.X, 5.4.X, 5.5.X, 5.6.X, 7.0.X) с установленными по умолчанию поставляемыми расширениями `mbstring`, `json`, `pcre`.
* Если необходимо, чтобы запросы отправлялись через HTTPS, то должно быть установлено расширение `openssl` у PHP.

  В случае использования `access_token` наличие расширения `openssl` обязательно, т.к. запросы с `access_token` могут отправляться только через HTTPS-протокол.
* Скрипт предпочитает использовать встроенные в PHP возможности по отправке запросов. Если у PHP отключена встроенная возможность загрузки файлов по URL (отключен параметр `allow_url_fopen` в конфигурации или параметрах интерпретатора), но при этом у PHP установлено расширение `cURL`, то именно оно будет использоваться для загрузки данных. 
* Если необходимо использовать прокси-сервер, то в случае
   * HTTP-прокси — в конфигурации или параметрах интерпретатора PHP должен быть включён параметр `allow_url_fopen` либо установлено расширение `cURL`>=7.10,
   * HTTPS-прокси — необходимо расширение `openssl`, а также в конфигурации или параметрах интерпретатора PHP должен быть включён параметр `allow_url_fopen` либо установлено расширение `cURL`>=7.10,
   * SOCKS5-прокси — необходимо расширение `cURL`>=7.10,
   * SOCKS4-прокси — необходим PHP>=5.2.10 с расширением `cURL`>=7.10,
   * SOCKS4A-прокси — необходим PHP>=5.5.23 или PHP>=5.6.7 (включая 7.0.X) с расширением `cURL`>=7.18.

В случае каких-либо проблем вместо RSS-ленты выдается страница с HTTP-статусом, отличным от 200, и с описанием проблемы, а также создаётся запись в журнале ошибок сервера или интерпретатора.


## Параметры:
Параметры `id` и `access_token` обязательны, остальные необязательны.

* [обязательный] `id` — короткое название, ID-номер (в случае сообщества ID начинается со знака `-`) или полный идентификатор человека/сообщества (в виде idXXXX, clubXXXX, publicXXXX, eventXXXX).  Примеры допустимых значений параметра `id`:
  * `123456`, `id123456` — оба значения указывают на одну и ту же страницу пользователя с ID 123456,
  * `-123456`, `club123456` — оба значения указывают на одну и ту же группу с ID 123456,
  * `-123456`, `public123456` — оба значения указывают на одну и ту же публичную страницу с ID 123456,
  * `-123456`, `event123456` — оба значения указывают на одну и ту же страницу мероприятия с ID 123456,
  * `apiclub` — значение указывает на пользователя или сообщество с данным коротким названием.
  
  Ради обратной совместимости допускается вместо `id` использовать `domain` или `owner_id`.

* [обязательный] `access_token` — 
   * Либо сервисный ключ доступа, который указан в настройках приложения (создать собственное standalone-приложение можно [по этой ссылке](https://vk.com/editapp?act=create)).
   
     Сервисный ключ доступа дает возможность получать записи только с открытых для всех стен.
   * Либо [ключ доступа пользователя с правами оффлайн-доступа](#Как-получить-бессрочный-токен-для-доступа-к-стенам-которые-доступны-своему-профилю).

     Ключ доступа пользователя позволяет получать записи как с открытых, так и закрытых стен (но открытых для пользователя, который создал токен).
     
     Если в настройках безопасности пользователя будут завершены все сессии, то ключ доступа пользователя станет невалидным — нужно сформировать токен заново.

* `count` — количество обрабатываемых записей, начиная с последней опубликованной (произвольное количество, включая более 100, по умолчанию 20). Если значение больше 100, то будут отправляться несколько запросов для получения записей, т.к. за один запрос можно получить не более 100 записей; между запросами задержка минимум в 1 секунду, чтобы не превышать ограничения VK API (не более 3 запросов в секунду).

  Если дополнительно установлен параметр `owner_only`, `include` или `exclude`, то количество выводимых в RSS-ленте записей может быть меньше значения `count` за счет исключения записей, которые отсеиваются параметром `owner_only`, `include` или `exclude`.

* `include` — регистронезависимое регулярное выражение в стиле PCRE, которое должно соответствовать тексту записи. В начале и в конце выражения символ `/` **не** нужен.
* `exclude` — регистронезависимое регулярное выражение в стиле PCRE, которое **не** должно соответствовать тексту записи. В начале и в конце выражения символ `/` **не** нужен.
* `disable_html` — если передан (можно без значения), то описание каждой записи не будет содержать никаких HTML тегов. По умолчанию (отсутствие `disable_html`) описание может включать HTML-теги для создания гиперссылок и вставки изображений.
* `owner_only` — если передан (можно без значения), то в RSS-ленту выводятся лишь те записи, которые 
   * в случае стены сообщества опубликованы от имени сообщества;
   
     если в этом случае дополнительно передан параметр `allow_signed=false`, то не будут выводиться подписанные записи, опубликованные от имени сообщества.
   * в случае стены пользователя опубликованы самим этим пользователем.

   
   По умолчанию (отсутствие параметра) выводятся записи ото всех, если они не фильтруются другими параметрами.
* `non_owner_only` или `not_owner_only` — если передан любой из них (можно без значения), то в RSS-ленту выводятся лишь те записи, которые 
  * в случае стены сообщества опубликованы не от имени сообщества, а пользователями; 
  
    если в этом случае дополнительно передан параметр `allow_signed`, то еще будут выводиться подписанные записи, опубликованные от имени сообщества;
  * в случае стены пользователя опубликованы не самим этим пользователем, а другими.
    
   По умолчанию (отсутствие параметра) выводятся записи ото всех, если они не фильтруются другими параметрами.
* `allow_signed` — допускать или нет подписанные записи, опубликованные от имени сообщества, если передан параметр `owner_only` или `non_owner_only`/`not_owner_only`.

   По умолчанию (отсутствие параметра) допустимы все записи, которые проходят фильтрацию другими параметрами. 

   Допустимые значения (регистр не учитывается): [отсутствие значения] (= `true`), `true`, `false`, `0` (= `false`), `1` (= `true`), все остальные значения воспринимаются как `true`.
   * в случае переданного параметра `owner_only` позволяет исключать подписанные записи путем передачи значения `false` параметра `allow_signed`,
   * в случае переданного параметра `non_owner_only` или `not_owner_only` позволяет дополнительно включать в RSS-ленту подписанные записи, опубликованные от имени сообщества, путем передачи значения `true` параметра `allow_signed`,
* `skip_ads` — если передан (можно без значения), то не выводить в RSS-ленте записи, помеченные как реклама. 

   По умолчанию (отсутствие параметра) выводятся все записи, если они не фильтруются другими параметрами.
   
   **Примечание**: API Вконтакте на момент коммита некорректно выдает метку о рекламе в случае репостов (о чем тех.поддержка уведомлена), поэтому рекламу-репосты данный параметр не фильтрует — выводит в RSS-ленту.
* `proxy` — адрес прокси-сервера. Допустимые форматы значения этого параметра:
    * `address`,
    * `address:port`,
    * `type://address`,
    * `type://address:port`,
    * `login:password@address`,
    * `login:password@address:port`,
    * `type://login:password@address`,
    * `type://login:password@address:port`,
    
    где `address` — домен или IP-адрес прокси, `port` — порт, `type` — тип прокси (HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5), `login` и `password` — логин и пароль для доступа к прокси, если необходимы. 
  
  Тип прокси и параметры авторизации можно передавать в виде отдельных параметров:
  * `proxy_type` — тип прокси (по умолчанию считается HTTP, если не указано в `proxy` и `proxy_type`),
  * `proxy_login` — логин для доступа к прокси-серверу,
  * `proxy_password` — пароль для доступа к прокси-серверу.


## Как получить бессрочный токен для доступа к стенам, которые доступны своему профилю
Для серверного доступа предпочтительна [такая схема](https://vk.com/dev/authcode_flow_user):

1. Создать собственное standalone-приложение [по этой ссылке](https://vk.com/editapp?act=create). По желанию в настройках приложения можно изменить состояние на «Приложение отключено» — это никак не помешает генерации RSS-ленты.

2. После авторизации под нужным профилем пройти по ссылке:

   `https://oauth.vk.com/authorize?client_id=APP_ID&display=page&redirect_uri&scope=offline&response_type=code&v=5.54`

   где вместо `APP_ID` подставить ID созданного приложения — его можно увидеть, например, в настройках приложения.

3. Подтвердить права. В результате в адресной строке будет GET-параметр `code`.

4. Пройти по ссылке:

    `https://oauth.vk.com/access_token?client_id=APP_ID&client_secret=APP_SECRET&redirect_uri&code=AUTH_CODE`

    где `APP_ID` — ID созданного приложения, `APP_SECRET` — защищенный ключ приложения (можно увидеть в настройках приложения), `AUTH_CODE` — значение параметра `code` из предыдущего шага.
 
    В результате будет выдан JSON-отклик с искомым `access_token` — именно это значение и следует использовать в качестве GET-параметра скрипта, генерирующего RSS-ленту.

5. При первом использовании токена с IP адреса, отличного от того, с которого получался токен, может выскочить ошибка "API Error 17: Validation required", требующая валидации: для этого необходимо пройти по первой ссылке из описания ошибки и ввести недостающие цифры номера телефона профиля.

В качестве бонуса в статистике созданного приложения можно смотреть частоту запросов к API.

**Внимание!** Если в настройках безопасности профиля будут завершены сессии приложения, то токен станет невалидным — нужно сформировать новый токен, повторив пункты 2-5.


## Примеры использования:
```php
index.php?id=apiclub&access_token=XXXXXXXXX
index.php?id=-1&access_token=XXXXXXXXX
index.php?id=id1&access_token=XXXXXXXXX
index.php?id=club1&access_token=XXXXXXXXX
index.php?id=club1&disable_html&access_token=XXXXXXXXX   # в данных RSS-ленты отсутстуют HTML-сущности
index.php?id=apiclub&count=100&include=рекомендуем&access_token=XXXXXXXXX   # выводятся только записи со словом 'рекомендуем'
index.php?id=apiclub&count=100&exclude=рекомендуем&access_token=XXXXXXXXX   # выводятся только записи без слова 'рекомендуем'
index.php?id=apiclub&proxy=localhost:8080&access_token=XXXXXXXXX
index.php?id=apiclub&proxy=localhost:8080&proxy_type=https&access_token=XXXXXXXXX
index.php?id=apiclub&proxy=https%3A%2F%2Flocalhost:8080&access_token=XXXXXXXXX
index.php?id=club1&owner_only&access_token=XXXXXXXXX   # выводятся только записи от имени сообщества
index.php?id=club1&owner_only&allow_signed=false&access_token=XXXXXXXXX   # выводятся только записи от имени сообщества,
                                                                          # у которых нет подписи
index.php?id=club1&non_owner_only&access_token=XXXXXXXXX   # выводятся только записи от пользователей (не от имени сообщества)
index.php?id=club1&non_owner_only&allow_signed&access_token=XXXXXXXXX   # выводятся только записи от имени сообщества, 
                                                                        # у которых есть подпись, и записи от пользователей
index.php?id=-1&count=100&include=(рекомендуем|приглашаем|\d{3,})&access_token=XXXXXXXXX
```
**Примечание**: в последнем примере при таком вызове напрямую через GET-параметры может потребоваться URL-кодирование символов: ```index.php?id=-1&count=100&include=(%D1%80%D0%B5%D0%BA%D0%BE%D0%BC%D0%B5%D0%BD%D0%B4%D1%83%D0%B5%D0%BC%7C%D0%BF%D1%80%D0%B8%D0%B3%D0%BB%D0%B0%D1%88%D0%B0%D0%B5%D0%BC%7C%5Cd%7B3%2C%7D)&access_token=XXXXXXXXX```
