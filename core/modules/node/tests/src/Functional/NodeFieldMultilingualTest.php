<?php

namespace Drupal\Tests\node\Functional;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Tests multilingual support for fields.
 *
 * @group node
 */
class NodeFieldMultilingualTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['node', 'language'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'classy';

  protected function setUp(): void {
    parent::setUp();

    // Create Basic page node type.
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);

    // Setup users.
    $admin_user = $this->drupalCreateUser([
      'administer languages',
      'administer content types',
      'access administration pages',
      'create page content',
      'edit own page content',
    ]);
    $this->drupalLogin($admin_user);

    // Add a new language.
    ConfigurableLanguage::createFromLangcode('it')->save();

    $config = $this->config('language.negotiation');
    $config->set('url.prefixes', [
      'en' => 'en',
      'de' => 'de',
    ])->save();

    $this->container->get('entity_type.manager')->getStorage('language_content_settings')->create([
      'target_entity_type_id' => $entity_type,
      'target_bundle' => $bundle,
    ])->save();

    // Make node body translatable.
    $field_storage = FieldStorageConfig::loadByName('node', 'body');
    $field_storage->setTranslatable(TRUE);
    $field_storage->save();
  }

  /**
   * Tests whether field languages are correctly set through the node form.
   */
  public function testMultilingualNodeForm() {
    // Create "Basic page" content.
    $langcode = language_get_default_langcode('node', 'page');
    $title_key = 'title[0][value]';
    $title_value = $this->randomMachineName(8);
    $body_key = 'body[0][value]';
    $body_value = $this->randomMachineName(16);

    // Create node to edit.
    $entity_type = [];
    $entity_type[$title_key] = $title_value;
    $entity_type[$body_key] = $body_value;
    $this->drupalPostForm('node/add/page', $entity_type, t('Save'));

    // Check that the node exists in the database.
    $node = $this->drupalGetNodeByTitle($entity_type[$title_key]);
    $this->assertNotEmpty($node, 'Node found in database.');
    $this->assertTrue($node->language()->getId() == $langcode && $node->body->value == $body_value, 'Field language correctly set.');

    // Change node language.
    $langcode = 'it';
    $this->drupalGet("node/{$node->id()}/edit");
    $entity_type = [
      $title_key => $this->randomMachineName(8),
      'langcode[0][value]' => $langcode,
    ];
    $this->drupalPostForm(NULL, $entity_type, t('Save'));
    $node = $this->drupalGetNodeByTitle($entity_type[$title_key], TRUE);
    $this->assertNotEmpty($node, 'Node found in database.');
    $this->assertTrue($node->language()->getId() == $langcode && $node->body->value == $body_value, 'Field language correctly changed.');

    // Enable content language URL detection.
    $this->container->get('language_negotiator')->saveConfiguration(LanguageInterface::TYPE_CONTENT, [LanguageNegotiationUrl::METHOD_ID => 0]);

    // Test multilingual field language fallback logic.
    $this->drupalGet("it/node/{$node->id()}");
    $this->assertRaw($body_value, 'Body correctly displayed using Italian as requested language');

    $this->drupalGet("node/{$node->id()}");
    $this->assertRaw($body_value, 'Body correctly displayed using English as requested language');
  }

  /**
   * Tests multilingual field display settings.
   */
  public function testMultilingualDisplaySettings() {
    // Create "Basic page" content.
    $title_key = 'title[0][value]';
    $title_value = $this->randomMachineName(8);
    $body_key = 'body[0][value]';
    $body_value = $this->randomMachineName(16);

    // Create node to edit.
    $entity_type = [];
    $entity_type[$title_key] = $title_value;
    $entity_type[$body_key] = $body_value;
    $this->drupalPostForm('node/add/page', $entity_type, t('Save'));

    // Check that the node exists in the database.
    $node = $this->drupalGetNodeByTitle($entity_type[$title_key]);
    $this->assertNotEmpty($node, 'Node found in database.');

    // Check if node body is showed.
    $this->drupalGet('node/' . $node->id());
    $body = $this->xpath('//article[contains(concat(" ", normalize-space(@class), " "), :node-class)]//div[contains(concat(" ", normalize-space(@class), " "), :content-class)]/descendant::p', [
      ':node-class' => ' node ',
      ':content-class' => 'node__content',
    ]);
    $this->assertEqual($body[0]->getText(), $node->body->value, 'Node body found.');
  }

}