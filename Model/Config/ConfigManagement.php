<?php

declare(strict_types=1);

namespace Akeneo\Connector\Model\Config;

use Akeneo\Connector\Block\Adminhtml\System\Config\Form\Field\Website;
use Akeneo\Connector\Helper\Config as ConfigHelper;
use Akeneo\Connector\Model\Source\Edition;
use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Asset\Repository;
use Zend_Pdf;
use Zend_Pdf_Action_URI;
use Zend_Pdf_Annotation_Link;
use Zend_Pdf_Canvas_Interface;
use Zend_Pdf_Exception;
use Zend_Pdf_Font;
use Zend_Pdf_Page;
use Zend_Pdf_Resource_Image_Png;

/**
 * Class ConfigManagement
 *
 * @package   Akeneo\Connector\Model\Config
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2004-present Agence Dn'D
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.dnd.fr/
 */
class ConfigManagement
{
    /**
     * Description $resourceConnection field
     *
     * @var ResourceConnection $resourceConnection
     */
    protected $resourceConnection;
    /**
     * Description $sourceEdition field
     *
     * @var Edition $sourceEdition
     */
    protected $sourceEdition;
    /**
     * Description $moduleReader field
     *
     * @var Reader $moduleReader
     */
    protected $moduleReader;
    /**
     * Description $configHelper field
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;
    /**
     * Description $assetRepository field
     *
     * @var Repository $assetRepository
     */
    protected $assetRepository;
    /**
     * Description $directoryList field
     *
     * @var DirectoryList $directoryList
     */
    protected $directoryList;
    /**
     * Description $serializer field
     *
     * @var SerializerInterface $serializer
     */
    protected $serializer;
    /**
     * Description $websiteFormField field
     *
     * @var Website $websiteFormField
     */
    protected $websiteFormField;
    /**
     * Description HIDDEN_FIELDS constant
     *
     * @var string[] HIDDEN_FIELDS
     */
    const HIDDEN_FIELDS = [
        ConfigHelper::AKENEO_API_BASE_URL,
        ConfigHelper::AKENEO_API_PASSWORD,
        ConfigHelper::AKENEO_API_USERNAME,
        ConfigHelper::AKENEO_API_CLIENT_ID,
        ConfigHelper::AKENEO_API_CLIENT_SECRET,
    ];
    /**
     * Description LINE_BREAK constant
     *
     * @var int LINE_BREAK
     */
    const LINE_BREAK = 20;
    /**
     * Indentation for multiselect list
     *
     * @var int INDENT_MULTISELECT
     */
    const INDENT_MULTISELECT = 120;
    /**
     * Indentation for footer
     *
     * @var int INDENT_FOOTER
     */
    const INDENT_FOOTER = 50;
    /**
     * Indentation for attributes list
     *
     * @var int INDENT_TEXT
     */
    const INDENT_TEXT = 100;
    /**
     * Array line Height
     *
     * @var int ARRAY_LINE_HEIGHT
     */
    const ARRAY_LINE_HEIGHT = 30;
    /**
     * Footer start position for Y axis
     *
     * @var int FOOTER_START_POSITION
     */
    const FOOTER_START_POSITION = 70;
    /**
     * Position in Y axis of the last element
     *
     * @var float $lastPosition
     */
    protected $lastPosition = 0;
    /**
     * Current PDF object
     *
     * @var Zend_Pdf $pdf
     */
    protected $pdf;
    /**
     * Current page object
     *
     * @var Zend_Pdf_Page $page
     */
    protected $page;

    /**
     * ConfigManagement constructor
     *
     * @param ResourceConnection  $resourceConnection
     * @param Edition             $sourceEdition
     * @param Reader              $moduleReader
     * @param ConfigHelper        $configHelper
     * @param Repository          $assetRepository
     * @param DirectoryList       $directoryList
     * @param SerializerInterface $serializer
     * @param Website             $websiteFormField
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        Edition $sourceEdition,
        Reader $moduleReader,
        ConfigHelper $configHelper,
        Repository $assetRepository,
        DirectoryList $directoryList,
        SerializerInterface $serializer,
        Website $websiteFormField
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->sourceEdition      = $sourceEdition;
        $this->moduleReader       = $moduleReader;
        $this->configHelper       = $configHelper;
        $this->assetRepository    = $assetRepository;
        $this->directoryList      = $directoryList;
        $this->serializer         = $serializer;
        $this->websiteFormField   = $websiteFormField;
    }

    /**
     * Description generatePdf function
     *
     * @return Zend_Pdf
     * @throws Zend_Pdf_Exception
     */
    public function generatePdf()
    {
        $this->pdf = new Zend_Pdf();
        $this->addNewPage();

        /** @var mixed[] $configs */
        $configs = $this->getAllAkeneoConfigs();

        /**
         * @var int      $index
         * @var string[] $config
         */
        foreach ($configs as $index => $config) {
            /** @var string $label */
            $label = $this->getSystemConfigAttribute($config['path'], 'label');
            /** @var string $value */
            $value = $label . ' : ';

            // Manage serialized attribute
            if ($this->getSystemConfigAttribute($config['path'], 'backend_model') === ArraySerialized::class) {
                $this->page->drawText($value, self::INDENT_TEXT, $this->lastPosition);

                // Get array labels
                /** @var string[] $configValueUnserialized */
                $configValueUnserialized = $this->serializer->unserialize($config['value']);
                /** @var string[] $firstElement */
                $firstElement = reset($configValueUnserialized);

                if (!$firstElement) {
                    $this->addLineBreak();
                    continue;
                }
                /** @var string[] $firstElementKeys */
                $firstElementKeys = array_keys($firstElement);

                $this->insertSerializedArray(
                    $configValueUnserialized,
                    $firstElementKeys
                );
                continue;
            }

            if ($config['value'] && str_contains($config['value'], ',')) {
                $this->page->drawText($value, self::INDENT_TEXT, $this->lastPosition);
                $this->insertMultiselect($config['value']);
                continue;
            }

            if (in_array($config['path'], self::HIDDEN_FIELDS) && $config['value']) {
                $value .= '****';
            } else {
                $value .= $config['value'];
            }

            if ($config['path'] === ConfigHelper::AKENEO_API_EDITION) {
                $value = $this->getEdition();
            }

            $this->page->drawText($value, 100, $this->lastPosition);

            $this->addLineBreak();
        }

        return $this->pdf;
    }

    /**
     * Description setPageStyle function
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function setPageStyle()
    {
        $style = new \Zend_Pdf_Style();
        $style->setLineColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));
        $font = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA);
        $style->setFont($font, 10);
        $this->page->setStyle($style);
    }

    /**
     * Insert multiselect into the pdf
     *
     * @param string $values
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function insertMultiselect($values)
    {
        /** @var string[] $valuesArray */
        $valuesArray = explode(',', $values);
        /** @var string $value */
        foreach ($valuesArray as $value) {
            $this->addLineBreak();
            $this->page->drawText('- ' . $value, self::INDENT_MULTISELECT, $this->lastPosition);
        }

        $this->addLineBreak();
    }

    /**
     * Description insertSerializedArray function
     *
     * @param string[] $values
     * @param string[] $headers
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function insertSerializedArray(array $values, array $headers)
    {
        /** @var float $maxLengthValue */
        $maxLengthValue = $this->getMaxLengthValue($values);

        /** @var float $rowLength */
        $rowLength = ($maxLengthValue * count($headers)) + 10;
        /** @var float $cellLength */
        $cellLength = $rowLength / count($headers);

        $this->addLineBreak();
        $this->addArrayRow($headers, $cellLength, $rowLength);
        // Footer detection
        $this->addLineBreak(self::ARRAY_LINE_HEIGHT, 0);

        /** @var string[] $value */
        foreach ($values as $value) {
            // Delete all keys of the array
            /** @var string[] $arrayValues */
            $arrayValues = [];
            /** @var string $attribute */
            foreach ($value as $attribute) {
                $arrayValues[] = $attribute;
            }
            $this->addArrayRow($arrayValues, $cellLength, $rowLength);
        }

        $this->addLineBreak();
    }

    /**
     * Description insertHeader function
     *
     * @param Zend_Pdf_Page $page
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function insertHeader(Zend_Pdf_Page $page)
    {
        /** @var string $title */
        $title = __('Akeneo Connector for Magento 2 - Configuration export');
        /** @var float $titleLength */
        $titleLength = $this->widthForStringUsingFontSize($title, $page);
        $page->drawText($title, ($page->getWidth() - $titleLength) / 2, $this->lastPosition);

        $this->addLineBreak();

        /** @var Zend_Pdf_Resource_Image_Png $image */
        $image = new Zend_Pdf_Resource_Image_Png(
            'data:image/png;base64,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'
        );
        /** @var mixed $imageWidth */
        $imageWidth = $image->getPixelWidth();
        /** @var mixed $imageHeigth */
        $imageHeigth = $image->getPixelHeight();
        /** @var float $x1 */
        $x1 = ($page->getWidth() - $imageWidth) / 2;
        /** @var float $x2 */
        $x2 = $x1 + $imageWidth;
        // Display Logo
        $page->drawImage($image, $x1, $this->lastPosition - $imageHeigth, $x2, $this->lastPosition);

        $this->addLineBreak(0, $imageHeigth);
    }

    /**
     * Description addNewPage function
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function addNewPage()
    {
        $this->page         = $this->pdf->newPage(Zend_Pdf_Page::SIZE_A4);
        $this->pdf->pages[] = $this->page;
        $this->setPageStyle();

        $this->lastPosition = $this->page->getHeight() - self::LINE_BREAK;

        if (count($this->pdf->pages) === 1) {
            $this->insertHeader($this->page);
        }
    }

    /**
     * Description getEdition function
     *
     * @return string
     */
    protected function getEdition()
    {
        /** @var string[] $versions */
        $versions = $this->sourceEdition->toOptionArray();

        return $versions[$this->configHelper->getEdition()];
    }

    /**
     * Description getDirectory function
     *
     * @return string
     */
    protected function getDirectory()
    {
        return $this->moduleReader->getModuleDir(
            Dir::MODULE_ETC_DIR,
            'Akeneo_Connector'
        );
    }

    /**
     * Description getSystemFileContent function
     *
     * @param string $path
     * @param string $attributeName
     *
     * @return bool|string
     */
    protected function getSystemConfigAttribute($path, $attributeName)
    {
        /** @var string[] $path */
        $path = explode('/', $path);
        /** @var string $etcDir */
        $etcDir = $this->moduleReader->getModuleDir(
            Dir::MODULE_ETC_DIR,
            'Akeneo_Connector'
        );
        /** @var mixed[] $xml */
        $xml = simplexml_load_file($etcDir . '/adminhtml/system.xml');
        /** @var string $label */
        $label = '';

        /** @var SimpleXMLElement $group */
        foreach ($xml->{'system'}->{'section'}->{'group'} as $group) {
            /** @var string[] $attributes */
            $attributes = $group->attributes();
            if ((string)$attributes['id'] === $path[1]) {
                foreach ($group->{'field'} as $field) {
                    /** @var string[] $attributes */
                    $attributes = $field->attributes();
                    if ((string)$attributes['id'] === $path[2]) {
                        $label = $field->{$attributeName};
                    }
                }
            }
        }

        return (string)$label;
    }

    /**
     * Description getAllAkeneoConfigs function
     *
     * @return mixed[]
     */
    protected function getAllAkeneoConfigs()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->resourceConnection->getConnection();
        /** @var Select $select */
        $select = $connection->select()->from(
            [
                'ccd' => 'core_config_data',
            ]
        )->where('path like ?', '%akeneo%');

        return $connection->fetchAll($select);
    }

    /**
     * Description addFooter function
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function addFooter()
    {
        /** @var string $text */
        $text = "If you want to report a bug, ask a question or have a suggestion to make on Akeneo Connector for Magento 2,";
        /** @var string $text2 */
        $text2 = "please follow this steps to contact our Support Team";

        $this->page->drawText($text, self::INDENT_FOOTER, self::FOOTER_START_POSITION - self::LINE_BREAK);
        $this->page->drawText($text2, self::INDENT_FOOTER, self::FOOTER_START_POSITION - (self::LINE_BREAK) * 2);

        $target     = Zend_Pdf_Action_URI::create(
            'https://help.akeneo.com/magento2-connector/v100/articles/download-connector.html#what-can-i-do-if-i-have-a-question-to-ask-a-bug-to-report-or-a-suggestion-to-make-about-the-connector'
        );
        $annotation = Zend_Pdf_Annotation_Link::create(
            127,
            $this->lastPosition - 30,
            155,
            $this->lastPosition - 30,
            $target
        );
        $this->page->attachAnnotation($annotation);
    }

    /**
     * Description widthForStringUsingFontSize function
     *
     * @param string $string
     *
     * @return float|int
     * @throws Zend_Pdf_Exception
     */
    protected function widthForStringUsingFontSize($string)
    {
        $drawingString = iconv('UTF-8', 'UTF-16BE//IGNORE', $string);
        $characters    = [];
        for ($i = 0; $i < strlen($drawingString); $i++) {
            $characters[] = (ord($drawingString[$i++]) << 8) | ord($drawingString[$i]);
        }

        $font        = $this->page->getFont();
        $glyphs      = $this->page->getFont()->glyphNumbersForCharacters($characters);
        $widths      = $font->widthsForGlyphs($glyphs);
        $stringWidth = (array_sum($widths) / $font->getUnitsPerEm()) * $this->page->getFontSize();

        return $stringWidth;
    }

    /**
     * Description getMaxLengthValue function
     *
     * @param string[] $values
     *
     * @return float
     * @throws Zend_Pdf_Exception
     */
    protected function getMaxLengthValue(array $values)
    {
        /** @var float $maxLength */
        $maxLength = 0;
        /** @var string[] $value */
        foreach ($values as $key => $value) {
            foreach ($value as $attributeKey => $attribute) {
                /** @var float $lenth */
                $lengthValue = $this->widthForStringUsingFontSize($attribute);
                $lengthKey   = $this->widthForStringUsingFontSize($attributeKey);
                if ($lengthValue > $maxLength) {
                    $maxLength = $lengthValue;
                }

                if ($lengthKey > $maxLength) {
                    $maxLength = $lengthKey;
                }
            }
        }

        return $maxLength;
    }

    /**
     * Add line break in the page
     *
     * @param float|null $nextElementHeight
     * @param float|null $value
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function addLineBreak($nextElementHeight = null, $value = null)
    {
        if (is_null($nextElementHeight)) {
            $nextElementHeight = 0;
        }

        if ($this->lastPosition <= self::FOOTER_START_POSITION || ($this->lastPosition - $nextElementHeight <= self::FOOTER_START_POSITION)) {
            $this->addFooter();
            $this->addNewPage();
        }

        if (is_null($value)) {
            $this->lastPosition -= self::LINE_BREAK;
        } else {
            $this->lastPosition -= $value;
        }
    }

    /**
     * Description addArrayLine function
     *
     * @param string[] $values
     * @param float    $cellLength
     * @param float    $rowLength
     *
     * @return void
     * @throws Zend_Pdf_Exception
     */
    protected function addArrayRow(
        array $values,
        $cellLength,
        $rowLength
    ) {
        /** @var Zend_Pdf_Canvas_Interface $line */
        $this->page->drawRectangle(
            self::INDENT_MULTISELECT,
            $this->lastPosition,
            self::INDENT_MULTISELECT + $rowLength,
            $this->lastPosition - self::ARRAY_LINE_HEIGHT,
            Zend_Pdf_Page::SHAPE_DRAW_STROKE
        );

        /**
         * @var int    $index
         * @var string $value
         */
        foreach ($values as $index => $value) {
            /** @var float $indentValueCell */
            $indentValueCell = ($cellLength * $index) + ($cellLength - $this->widthForStringUsingFontSize(
                        $value
                    )) / 2;

            $this->page->drawText(
                $value,
                self::INDENT_MULTISELECT + $indentValueCell,
                $this->lastPosition - 20
            );

            // Draw the rightl line of the cell
            $this->page->drawLine(
                self::INDENT_MULTISELECT + ($cellLength * $index) + $cellLength,
                $this->lastPosition,
                self::INDENT_MULTISELECT + ($cellLength * $index) + $cellLength,
                $this->lastPosition - self::ARRAY_LINE_HEIGHT
            );
        }

        $this->addLineBreak(0, self::ARRAY_LINE_HEIGHT);
    }
}
