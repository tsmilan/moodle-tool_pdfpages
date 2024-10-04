<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_pdfpages;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Cookies\Cookie;
use HeadlessChromium\Page;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/admin/tool/pdfpages/vendor/autoload.php');

/**
 * Class for converting Moodle pages to PDFs using chromium/chrome.
 *
 * @package    tool_pdfpages
 * @author     Tom Dickman <tomdickman@catalyst-au.net>
 * @copyright  2021 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class converter_chromium extends converter {

    /**
     * Converter name.
     */
    protected const NAME = 'chromium';

    /**
     * A list of valid options, keyed by option with value being a description.
     */
    protected const VALID_OPTIONS = [
        'landscape' => '(bool) print PDF in landscape',
        'printBackground' => '(bool) print background colors and images',
        'displayHeaderFooter' => '(bool) display header and footer',
        'headerTemplate' => '(string) HTML template to use as header',
        'footerTemplate' => '(string) HTML template to use as footer',
        'paperWidth' => '(float) paper width in inches',
        'paperHeight' => '(float) paper height in inches',
        'marginTop' => '(float) margin top in inches',
        'marginBottom' => '(float) margin bottom in inches',
        'marginLeft'  => '(float) margin left in inches',
        'marginRight' => '(float) margin right in inches',
        'preferCSSPageSize' => '(bool) read params directly from @page',
        'scale' => '(float) scale the page',
    ];

    /**
     * Generate the PDF content of a target URL passed through proxy URL.
     *
     * @param \moodle_url $proxyurl the plugin proxy url for access key login and redirection to target URL.
     * @param string $filename the name to give converted file.
     * @param array $options any additional options to pass to converter, valid options vary with converter
     * instance, see relevant converter for further details.
     * @param string $cookiename cookie name to apply to conversion (optional).
     * @param string $cookievalue cookie value to apply to conversion (optional).
     * @param array $renderoptions Chromium-specific options:
     *        - windowSize: An array specifying the size of the browser window, e.g. [1920, 1080].
     *        - userAgent: A string representing the custom user agent to use when navigating the page.
     *        - jsCondition: A JavaScript condition to be evaluated, specified as a string.
     *                It should return a boolean value indicating whether the condition has been met.
     *        - jsConditionParams: An array of parameters to pass to the Javascript function.
     *
     * @return string raw PDF content of URL.
     */
    protected function generate_pdf_content(moodle_url $proxyurl, string $filename = '', array $options = [],
                                            string $cookiename = '', string $cookievalue = '',
                                            array $renderoptions = []): string {
        try {
            $browseroptions = [
                'headless' => true,
                'noSandbox' => true
            ];

            if (!is_null($renderoptions['windowSize'])) {
                $browseroptions['windowSize'] = $renderoptions['windowSize'];
            }

            $browserfactory = new BrowserFactory(helper::get_config($this->get_name() . 'path'));
            $browser = $browserfactory->createBrowser($browseroptions);

            $page = $browser->createPage();
            if (!empty($cookiename) && !empty($cookievalue)) {
                $page->setCookies([
                    Cookie::create($cookiename, $cookievalue, [
                        'domain' => urldecode($proxyurl->get_param('url')),
                        'expires' => time() + DAYSECS
                    ])
                ])->await();
            }

            if (!empty($renderoptions['userAgent'])) {
                $page->setUserAgent($renderoptions['userAgent']);
            }

            $page->navigate($proxyurl->out(false))->waitForNavigation();

            $timeout = 1000 * helper::get_config($this->get_name() . 'responsetimeout');
            $this->wait_for_js_condition($page, $renderoptions['jsCondition'], $renderoptions['jsConditionParams'], $timeout);

            $pdf = $page->pdf($options);

            return base64_decode($pdf->getBase64($timeout));
        } finally {
            // Always close the browser instance to ensure that chromium process is stopped.
            if (!empty($browser) && $browser instanceof Browser) {
                $browser->close();
            }
        }
    }

    /**
     * Wait for a JavaScript condition on the page to be true, within a specified timeout.
     *
     * This function will repeatedly evaluate the provided JavaScript condition with a 0.5 second
     * pause between checks, until it returns true or the timeout is exceeded.
     *
     * @param Page $page The current browser page.
     * @param string|null $jscondition The JavaScript condition to be evaluated. This should be a function as a string,
     * and should return a boolean value indicating whether the condition has been met.
     * @param array $jsconditionparams An array of parameters to pass to the Javascript function.
     * @param int $timeout The maximum time to wait for the MathJax to finish processing, in milliseconds.
     * Defaults to 30000ms (30 seconds).
     * @throws \moodle_exception If the JavaScript condition does not finish within the specified timeout.
     */
    protected function wait_for_js_condition(Page $page, ?string $jscondition = null, array $jsconditionparams = [],
            int $timeout = 30000): void {

        if (empty($jscondition)) {
            return;
        }

        $isconditionmet = false;
        $starttime = microtime(true);

        while (!$isconditionmet) {
            $evaluation = $page->callFunction($jscondition, $jsconditionparams);

            if ($evaluation->getReturnValue() === true) {
                $isconditionmet = true;
            } else {
                // Wait 0.5 seconds before rechecking.
                usleep(500000);

                // Calculate elapsed time and check if timeout is exceeded.
                $elapsedtime = (microtime(true) - $starttime) * 1000;
                if ($elapsedtime >= $timeout) {
                    throw new \moodle_exception('error:mathjaxtimeout', 'tool_pdfpages');
                }
            }
        }
    }

    /**
     * Validate the renderoptions array to ensure it contains valid keys and values.
     *
     * @param array $renderoptions Chromium-specific options such as windowSize, userAgent,
     * jsCondition and jsParams.
     * @return array Validated render options.
     * @throws \coding_exception If the options are invalid.
     */
    protected function validate_render_options(array $renderoptions): array {
        if (isset($renderoptions['windowSize']) && !is_array($renderoptions['windowSize'])) {
            throw new \coding_exception('Invalid windowSize: It must be an array with two integer values.');
        }

        if (isset($renderoptions['userAgent']) && !is_string($renderoptions['userAgent'])) {
            throw new \coding_exception('Invalid userAgent: It must be a string.');
        }

        if (isset($renderoptions['jsCondition']) && !is_string($renderoptions['jsCondition'])) {
            throw new \coding_exception('Invalid jsCondition: It must be a JavaScript function specified as a string.');
        }

        if (isset($renderoptions['jsConditionParams']) && !is_array($renderoptions['jsConditionParams'])) {
            throw new \coding_exception('Invalid jsConditionParams: It must be an array.');
        }

        return [
            'windowSize' => $renderoptions['windowSize'] ?? null,
            'userAgent' => $renderoptions['userAgent'] ?? '',
            'jsCondition' => $renderoptions['jsCondition'] ?? null,
            'jsConditionParams' => $renderoptions['jsConditionParams'] ?? [],
        ];
    }

    /**
     * Validate a list of options.
     *
     * @param array $options any additional options to pass to conversion.
     * {@see \tool_pdfpages\converter_chromium::VALID_OPTIONS}
     *
     * @return array validated options.
     */
    protected function validate_options(array $options): array {
        $validoptions = [];

        foreach ($options as $option => $value) {
            if (array_key_exists($option, self::VALID_OPTIONS)) {
                $validoptions[$option] = $value;
            }
        }

        return $validoptions;
    }
}
