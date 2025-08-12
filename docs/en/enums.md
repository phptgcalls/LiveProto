# Enumerations

Here we want to introduce the Enumerations that are used in <mark>LiveProto</mark>

?> Note, The namespace of all of them is `Tak\Liveproto\Enums\__X__`

---

## Authentication

- Cases
  - NEEDAUTHENTICATION <kbd>0</kbd>
  - NEEDCODE <kbd>1</kbd>
  - NEEDPASSWORD <kbd>2</kbd>
  - LOGIN <kbd>3</kbd>

You can take the step in which your session is now using the following method and do the next step yourself

```php
$step = $client->getStep();

if($step === Authentication::NEEDCODE){
	$client->sign_in(code : '12345');
} elseif($step === Authentication::LOGIN){
	$client->account->updateProfile(about : 'I am now using LiveProto 🌱');
}
```

---

## CommandType

- Cases
  - DOT <kbd>.</kbd>
  - SLASH <kbd>/</kbd>
  - BACKSLASH <kbd>\\</kbd>
  - EXCLAMATION <kbd>!</kbd>
  - COLON <kbd>:</kbd>
  - SEMICOLON <kbd>;</kbd>
  - HASH <kbd>#</kbd>
  - DOLLAR <kbd>$</kbd>
  - AMPERSAND <kbd>&</kbd>
  - ASTERISK <kbd>*</kbd>
  - CARET <kbd>^</kbd>
  - TILDE <kbd>~</kbd>
  - PIPE <kbd>|</kbd>
  - AT <kbd>@</kbd>

This is used in the [Command Filter](en/filters.md#Command) related to [update handlers](en/handlers.md), and pay attention to the example below

```php
#[Filter(new NewMessage(new Command(hi : CommandType::AT,hello : CommandType::DOT)))]
function newUpdate(Incoming & IsPrivate $update) : void {
	$update->reply('Hi ! Are you okay ? 😕');
}
```

> It accepts messages in the form of `@hi` and `.Hello` only

---

## EmailPurpose

- Cases
  - LOGINSETUP <kbd>emailVerifyPurposeLoginSetup</kbd>
  - LOGINCHANGE <kbd>emailVerifyPurposeLoginChange</kbd>
  - PASSPORT <kbd>emailVerifyPurposePassport</kbd>

It is used for [`send_email_code`](en/methods.md#send_email_code) and [`verify_email`](en/methods.md#verify_email) methods

```php
$client->send_email_code(email : ' Tak@liveproto.dev',email_purpose : EmailPurpose::LOGINCHANGE);

$client->verify_email(code : 123456,email_purpose : EmailPurpose::LOGINCHANGE);
```

---

## ProtocolType

- Cases
  - FULL <kbd>TcpFull</kbd>
  - ABRIDGED <kbd>TcpAbridged</kbd>
  - INTERMEDIATE <kbd>TcpIntermediate</kbd>
  - PADDEDINTERMEDIATE <kbd>TcpPaddedIntermediate</kbd>
  - OBFUSCATED <kbd>TcpObfuscated</kbd>
  - HTTP <kbd>Http</kbd>

It is used in [`settings`](en/quickstart.md#Settings)

```php
$settings->setProtocol(ProtocolType::ABRIDGED);
```

---

## FileIdType

- Cases
  - THUMBNAIL <kbd>thumbnail</kbd>
  - PROFILE_PHOTO <kbd>profile_photo</kbd>
  - PHOTO <kbd>photo</kbd>
  - VOICE <kbd>voice</kbd>
  - VIDEO <kbd>video</kbd>
  - DOCUMENT <kbd>document</kbd>
  - ENCRYPTED <kbd>encrypted</kbd>
  - TEMP <kbd>temp</kbd>
  - STICKER <kbd>sticker</kbd>
  - AUDIO <kbd>audio</kbd>
  - ANIMATION <kbd>animation</kbd>
  - ENCRYPTED_THUMBNAIL <kbd>encrypted_thumbnail</kbd>
  - WALLPAPER <kbd>wallpaper</kbd>
  - VIDEO_NOTE <kbd>video_note</kbd>
  - SECURE_DECRYPTED <kbd>secure_decrypted</kbd>
  - SECURE_ENCRYPTED <kbd>secure_encrypted</kbd>
  - BACKGROUND <kbd>background</kbd>
  - DOCUMENT_AS_FILE <kbd>document_as_file</kbd>
  - RINGTONE <kbd>ringtone</kbd>
  - CALL_LOG <kbd>call_log</kbd>
  - PHOTO_STORY <kbd>photo_story</kbd>
  - VIDEO_STORY <kbd>video_story</kbd>
  - SELF_DESTRUCTING_PHOTO <kbd>self_destructing_photo</kbd>
  - SELF_DESTRUCTING_VIDEO <kbd>self_destructing_video</kbd>
  - SELF_DESTRUCTING_VIDEONOTE <kbd>self_destructing_videonote</kbd>
  - SELF_DESTRUCTING_VOICENOTE <kbd>self_destructing_voicenote</kbd>
  - SIZE <kbd>size</kbd>

You will see such a thing only when getting the file info from file id bot api

```php
$info = $client->fromBotAPI(file_id : 'AgACAgEAAxkBAAL8XWdvz06uHzhKi17HUUnqAAFfFYuaewACN64xG2S5eUfdMz8mKD2olQEAAwIAA3MAAzYE');

var_dump($info->file_type); // enum(Tak\Liveproto\Enums\FileIdType::PROFILE_PHOTO)
```

---

## PeerType

- Cases
  - SELF <kbd>self</kbd>
  - USER <kbd>user</kbd>
  - BOT <kbd>bot</kbd>
  - CHAT <kbd>chat</kbd>
  - GIGAGROUP <kbd>gigagroup</kbd>
  - MEGAGROUP <kbd>megagroup</kbd>
  - CHANNEL <kbd>channel</kbd>

You can use the following method to get the peer type to find out what this @username is related to, channel or group , ...

```php
var_dump($client->get_peer_type('@LiveProto')); // enum(Tak\Liveproto\Enums\PeerType::CHANNEL)
```

---

## RekeyState

- Cases
  - IDLE <kbd>0</kbd>
  - REQUESTED <kbd>1</kbd>
  - ACCEPTED <kbd>2</kbd>

```php
var_dump($client->get_secret(3416815559)['rekey']);
```