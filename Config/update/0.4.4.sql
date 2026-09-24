SET FOREIGN_KEY_CHECKS = 0;

-- MYO-475: dedicated transactional e-mail for the newsletter opt-in
-- scenario (subscribe_to_newsletter tool / NewsletterOptinScenarioResolver).
-- The coupon code only ever leaves the server through this e-mail, never in
-- a JSON response or the chat conversation. Content lives directly in
-- message_i18n (no template file) -- same pattern as e.g.
-- vendor/thelia/modules/Cheque/Config/setup.sql: a module has no mail-theme
-- directory of its own to put .html/.txt files in. Delete-then-insert keeps
-- this file safely replayable (see the comment on CommerceAgents::update()).
--
-- MYO-524: this message has no template file, so MailerFactory::getParser()
-- falls back to ParserResolver::getDefaultParser() -- on this install that is
-- TwigEngine (priority 10) over TheliaSmarty (priority 0), not Smarty. The
-- original content below was Smarty syntax ({$var}, {config key="..."}),
-- which TwigParser::renderString() does not understand and leaves verbatim
-- in the sent e-mail. Twig equivalent: {{ var }} for a message parameter
-- (NewsletterOptinService::sendCouponEmailIfAny() assigns coupon_code /
-- discount_label / condition_label), {{ config('key') }} for the Smarty
-- {config key="..."} tag (TwigEngine\Extension\ConfigExtension, the Twig
-- counterpart of Thelia\Core\Template\Helper\ConfigAccess).
SET @var := (SELECT `id` FROM `message` WHERE `name` = 'commerceagents_newsletter_optin_coupon' LIMIT 1);
DELETE FROM `message_i18n` WHERE `id` = @var;
DELETE FROM `message` WHERE `id` = @var;

SET @max := (SELECT IFNULL(MAX(`id`), 0) FROM `message`);
SET @max := @max + 1;

INSERT INTO `message` (`id`, `name`, `secured`) VALUES
  (@max, 'commerceagents_newsletter_optin_coupon', '0');

INSERT INTO `message_i18n` (`id`, `locale`, `title`, `subject`, `text_message`, `html_message`) VALUES
  (@max,
   'fr_FR',
   'Code promo newsletter (Commerce Agents)',
   'Votre code promo {{ config(''store_name'') }}',
   'Bonjour,\r\n\r\nMerci de vous être abonné à la newsletter de {{ config(''store_name'') }} !\r\n\r\nVoici votre code promo : {{ coupon_code }} ({{ discount_label }})\r\n\r\nUtilisez-le lors de votre prochaine commande sur {{ config(''url_site'') }}.\r\n\r\nA bientôt !\r\nL''équipe {{ config(''store_name'') }}',
   '<p>Bonjour,</p><p>Merci de vous être abonné à la newsletter de {{ config(''store_name'') }} !</p><p>Voici votre code promo : <strong>{{ coupon_code }}</strong> ({{ discount_label }})</p><p>Utilisez-le lors de votre prochaine commande sur <a href="{{ config(''url_site'') }}">{{ config(''url_site'') }}</a>.</p><p>A bientôt !<br>L''équipe {{ config(''store_name'') }}</p>'
  ),
  (@max,
   'en_US',
   'Newsletter opt-in coupon (Commerce Agents)',
   'Your {{ config(''store_name'') }} promo code',
   'Hello,\r\n\r\nThank you for subscribing to the {{ config(''store_name'') }} newsletter!\r\n\r\nHere is your promo code: {{ coupon_code }} ({{ discount_label }})\r\n\r\nUse it on your next order at {{ config(''url_site'') }}.\r\n\r\nSee you soon!\r\nThe {{ config(''store_name'') }} team',
   '<p>Hello,</p><p>Thank you for subscribing to the {{ config(''store_name'') }} newsletter!</p><p>Here is your promo code: <strong>{{ coupon_code }}</strong> ({{ discount_label }})</p><p>Use it on your next order at <a href="{{ config(''url_site'') }}">{{ config(''url_site'') }}</a>.</p><p>See you soon!<br>The {{ config(''store_name'') }} team</p>'
  );

SET FOREIGN_KEY_CHECKS = 1;
