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

use moodle_url;
use tool_pdfpages\pdf;

/**
 * Interface for converting Moodle pages to PDFs.
 *
 * @package    tool_pdfpages
 * @author     Tom Dickman <tomdickman@catalyst-au.net>
 * @copyright  2021 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class converter {

    /**
     * Converter name, override in extending classes.
     */
    protected const NAME = 'base';

    /**
     * Generate the PDF content of a target URL passed through proxy URL.
     *
     * @param \moodle_url $proxyurl the plugin proxy url for access key login and redirection to target URL.
     * @param string $filename the name to give converted file.
     * @param array $options any additional options to pass to converter, valid options vary with converter
     * instance, see relevant converter for further details.
     * @param string $cookiename cookie name to apply to conversion (optional).
     * @param string $cookievalue cookie value to apply to conversion (optional).
     * @param array $windowsize Size of the browser window. ex: `[1920, 1080]` (optional).
     * @param string $useragent A custom User Agent to use when navigating the page (optional).
     * @param string|null $jscondition The JavaScript condition to be evaluated. This should be a function as a string,
     * and should return a boolean value indicating whether the condition has been met (optional).
     * @param array $jsconditionparams An array of parameters to pass to the Javascript function (optional).
     *
     * @return string raw PDF content of URL.
     */
    abstract protected function generate_pdf_content(moodle_url $proxyurl, string $filename = '', array $options = [],
                               string $cookiename = '', string $cookievalue = '', array $windowsize = [],
                               string $useragent = '', ?string $jscondition = null, array $jsconditionparams = []): string;

    /**
     * Convert a moodle URL to PDF and store in file system.
     * Note: If the currently logged in user does not have the correct capabilities to view the
     * target URL, the created PDF will most likely be an error page.
     *
     * @param \moodle_url $url the target URL to convert.
     * @param string $filename the name to give converted file.
     * (if none is specified, filename will be generated {@see \tool_pdfpages\helper::get_moodle_url_pdf_filename})
     * @param array $options any additional options to pass to converter, valid options vary with converter
     * instance, see relevant converter for further details.
     * @param bool $keepsession should session be maintained after conversion? (For security reasons, this should always be `false`
     * when conducting a conversion outside of a browser window, such as in an adhoc task or other background process, to prevent
     * session hijacking.)
     * @param string $cookiename cookie name to apply to conversion (optional).
     * @param string $cookievalue cookie value to apply to conversion (optional).
     * @param array $windowsize Size of the browser window. ex: `[1920, 1080]` (optional).
     * @param string $useragent A custom User Agent to use when navigating the page (optional).
     * @param string|null $jscondition The JavaScript condition to be evaluated. This should be a function as a string,
     * and should return a boolean value indicating whether the condition has been met (optional).
     * @param array $jsconditionparams An array of parameters to pass to the Javascript function (optional).
     *
     * @return \stored_file the stored file created during conversion.
     */
    final public function convert_moodle_url_to_pdf(moodle_url $url, string $filename = '', array $options = [],
            bool $keepsession = false, string $cookiename = '', string $cookievalue = '', array $windowsize = [],
            string $useragent = '', ?string $jscondition = null, array $jsconditionparams = []): \stored_file {
        global $USER;

        try {
            $options = $this->validate_options($options);

            $filename = ($filename === '') ? helper::get_moodle_url_pdf_filename($url) : $filename;
            $key = key_manager::create_user_key_for_url($USER->id, $url);
            $proxyurl = helper::get_proxy_url($url, $key);
            $content = $this->generate_pdf_content($proxyurl, $filename, $options, $cookiename, $cookievalue,
                $windowsize, $useragent, $jscondition, $jsconditionparams);

            return $this->create_pdf_file($content, $filename);
        } catch (\Exception $exception) {
            throw new \moodle_exception('error:urltopdf', 'tool_pdfpages', '', null, $exception->getMessage());
        } finally {
            if (!$keepsession) {
                // Make sure the access key token session cannot be used for any other requests, prevent session hijacking.
                \core\session\manager::terminate_current();
            }
        }
    }

    /**
     * Convert the given moodle URLs to PDF and store them in the file system.
     * Note: If the currently logged in user does not have the correct capabilities to view the
     * target URL, the created PDF will most likely be an error page.
     *
     * @param array $urls An array of moodle_url objects representing the target URLs to convert.
     * @param string $filename the name to give converted file.
     * (if none is specified, filename will be generated {@see \tool_pdfpages\helper::get_moodle_url_pdf_filename})
     * @param array $options any additional options to pass to converter, valid options vary with converter
     * instance, see relevant converter for further details.
     * @param bool $keepsession should session be maintained after conversion? (For security reasons, this should always be `false`
     * when conducting a conversion outside of a browser window, such as in an adhoc task or other background process, to prevent
     * session hijacking.)
     * @param string $cookiename cookie name to apply to conversion (optional).
     * @param string $cookievalue cookie value to apply to conversion (optional).
     * @param array $windowsize Size of the browser window. ex: `[1920, 1080]` (optional).
     * @param string $useragent A custom User Agent to use when navigating the page (optional).
     * @param string $outputfilepath The file path to store the output file.
     * @param bool $printpagenumbers Whether to print page numbers in the footer of the combined PDF (optional,
     *                               defaults to false).
     *
     * @return \stored_file the stored file created during conversion.
     */
    final public function convert_moodle_urls_to_pdf(array $urls, string $filename = '', array $options = [],
            bool $keepsession = false, string $cookiename = '', string $cookievalue = '', array $windowsize = [],
            string $useragent = '', string $outputfilepath = '', bool $printpagenumbers = false): \stored_file {
        global $USER;

        $allurlsarevalid = empty(array_filter($urls, fn($url): bool => !$url instanceof moodle_url));
        if (!$allurlsarevalid) {
            throw new \coding_exception('All elements in the array must be an instance of moodle_url.');
        }

        try {
            $options = $this->validate_options($options);
            $pdffilepaths = [];

            foreach ($urls as $url) {
                $filename = ($filename === '') ? helper::get_moodle_url_pdf_filename($url) : $filename;
                $key = key_manager::create_user_key_for_url($USER->id, $url);
                $proxyurl = helper::get_proxy_url($url, $key);


                $pdfcontent = $this->generate_pdf_content($proxyurl, $filename, $options, $cookiename, $cookievalue, $windowsize, $useragent);
                $temppdf = $this->create_pdf_file($pdfcontent, $filename);
                $pdffilepaths[] = $temppdf->copy_content_to_temp();
                $temppdf->delete();
            }

            $pdf = new pdf();
            $pdf->combine_pdfs($pdffilepaths, $outputfilepath, $printpagenumbers);
            $combinedpdfcontent = file_get_contents($outputfilepath);

            // Return the combined PDF as a stored file.
            return $this->create_pdf_file($combinedpdfcontent, $outputfilepath);
        } catch (\Exception $exception) {
            throw new \moodle_exception('error:urltopdf', 'tool_pdfpages', '', null, $exception->getMessage());
        } finally {
            // Clean up the temporary PDF files.
            foreach ($pdffilepaths as $pdffilepath) {
                if (file_exists($pdffilepath)) {
                    unlink($pdffilepath);
                }
            }

            if (!$keepsession) {
                // Make sure the access key token session cannot be used for any other requests, prevent session hijacking.
                \core\session\manager::terminate_current();
            }
        }
    }

    /**
     * Create a PDF file from content.
     *
     * @param string $content the PDF content to write to file.
     * @param string $filename the filename to give file.
     *
     * @return bool|\stored_file the file or false if file could not be created.
     */
    public function create_pdf_file(string $content, string $filename) {

        $filerecord = helper::get_pdf_filerecord($filename, $this->get_name());
        $fs = get_file_storage();
        $existingfile = $fs->get_file(...array_values($filerecord));

        // If the file already exists, it needs to be deleted, as otherwise the new filename will collide
        // with existing filename and the new file will not be able to be created.
        if (!empty($existingfile)) {
            $existingfile->delete();
        }

        return $fs->create_file_from_string($filerecord, $content);
    }

    /**
     * Get a previously converted URL PDF.
     *
     * @param string $filename the filename of conversion file to get.
     *
     * @return bool|\stored_file the file or false if file could not be found.
     */
    public function get_converted_moodle_url_pdf(string $filename) {
        $filerecord = helper::get_pdf_filerecord($filename, $this->get_name());
        $fs = get_file_storage();

        return $fs->get_file(...array_values($filerecord));
    }

    /**
     * Get the converter name.
     *
     * @return string the converter name.
     */
    public function get_name(): string {
        return static::NAME;
    }

    /**
     * Check if this converter is enabled.
     *
     * @return bool true if converter enabled, false otherwise.
     */
    public function is_enabled(): bool {
        try {
            helper::get_config($this->get_name() . 'path');
            return true;
        } catch (\moodle_exception $exception) {
            return false;
        }
    }

    /**
     * Hook to validate options before conversion, override in extending classes.
     *
     * @param array $options any additional options to pass to conversion, valid options vary with converter
     * instance, see relevant converter for further details.
     *
     * @return array validated options.
     */
    protected function validate_options(array $options): array {
        return $options;
    }
}
