<?php
/**
 * namumark.php - Namu Mark Renderer
 * Copyright (C) 2015 koreapyj koreapyj0@gmail.com
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 */

class PlainWikiPage {
	public $title, $text, $lastchanged;
	function __construct($text) {
		$this->title = '(inline wikitext)';
		$this->text = $text;
		$this->lastchanged = time();
	}

	function getPage($name) {
		return new PlainWikiPage('');
	}
}


class NamuMark {
	public $prefix, $lastchange;

	function __construct($wtext) {

		$this->list_tag = array(
			array('*', 'ul'),
			#array('1.', 'ol class="decimal"'),
			array('A.', 'ol class="upper-alpha"'),
			array('a.', 'ol class="lower-alpha"'),
			array('I.', 'ol class="upper-roman"'),
			array('i.', 'ol class="lower-roman"')
			);

		$this->h_tag = array(
			array('/^====== (.*) ======$/', 6),
			array('/^===== (.*) =====$/', 5),
			array('/^==== (.*) ====$/', 4),
			array('/^=== (.*) ===$/', 3),
			array('/^== (.*) ==$/', 2),
			array('/^= (.*) =$/', 1),

			null
			);

		$this->multi_bracket = array(
			array(
				'open'	=> '{{{',
				'close' => '}}}',
				'multiline' => true,
				'processor' => array($this,'renderProcessor')),
			array(
				'open'	=> '<pre>',
				'close' => '</pre>',
				'multiline' => true,
				'processor' => array($this,'renderProcessor')),
			array(
				'open'	=> '{{|',
				'close' => '|}}',
				'multiline' => true,
				'processor' => array($this,'renderProcessor')),
			array(
				'open'	=> '<nowiki>',
				'close' => '</nowiki>',
				'multiline' => true,
				'processor' => array($this,'renderProcessor')),	
			
			);

		$this->single_bracket = array(
			array(
				'open'	=> '{{{',
				'close' => '}}}',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '[[',
				'close' => ']]',
				'multiline' => false,
				'processor' => array($this,'linkProcessor')),
			array(
				'open'	=> '[',
				'close' => ']',
				'multiline' => false,
				'processor' => array($this,'macroProcessor')),
			array(
				'open'	=> '~~',
				'close' => '~~',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '--',
				'close' => '--',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '__',
				'close' => '__',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '^^',
				'close' => '^^',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> ',,',
				'close' => ',,',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '$ ',
				'close' => ' $',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '<!--',
				'close' => '-->',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '{{|',
				'close' => '|}}',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '<nowiki>',
				'close' => '</nowiki>',
				'multiline' => false,
				'processor' => array($this,'textProcessor')),
			array(
				'open'	=> '<<',
				'close' => '>>',
				'multiline' => false,
				'processor' => array($this,'macroProcessor')),

			);
		
		$this->WikiPage = $wtext;

		$this->toc = array();
		$this->fn = array();
		$this->fn_cnt = 0;
		$this->prefix = '';
	}

	public function toHtml() {
		$this->whtml = $this->WikiPage->text;
		$this->whtml = $this->htmlScan($this->whtml);
		return $this->whtml;
	}

	private function htmlScan($text) {
		$result = '';
		$len = strlen($text);
		$now = '';
		$line = '';



		for($i=0;$i<$len;self::nextChar($text,$i)) {
			$now = self::getChar($text,$i);
			if($line == '' && $now == ' ' && $list = $this->listParser($text, $i)) {
				$result .= ''
					.$list
					.'';
				$line = '';
				$now = '';
				continue;
			}

			
			if(self::startsWith($text, '|', $i) && $table = $this->tableParser($text, $i)) {
				$result .= ''
					.$table
					.'';
				$line = '';
				$now = '';
				continue;
			}

			if($line == '' && self::startsWith($text, '>', $i) && $blockquote = $this->bqParser($text, $i)) {
				$result .= ''
					.$blockquote
					.'';
				$line = '';
				$now = '';
				continue;
			}

			foreach($this->multi_bracket as $bracket) {
				if(self::startsWith($text, $bracket['open'], $i) && $innerstr = $this->bracketParser($text, $i, $bracket)) {
					$result .= ''
						.$this->lineParser($line, '')
						.$innerstr
						.'';
					$line = '';
					$now = '';
					break;
				}
			}

			if($now == "\n") { // line parse
				$result .= $this->lineParser($line, '');
				$line = '';
			}
			else
				$line.=$now;
		}
		if($line != '')
			$result .= $this->lineParser($line, 'notn');
		return $result;
	}

	private function bqParser($text, &$offset) {
		$len = strlen($text);		
		$innerhtml = '';
		for($i=$offset;$i<$len;$i=self::seekEndOfLine($text, $i)+1) {
			$eol = self::seekEndOfLine($text, $i);
			if(!self::startsWith($text, '>', $i)) {
				// table end
				break;
			}
			$i+=1;
			$innerhtml .= $this->formatParser(substr($text, $i, $eol-$i))."<br>";
		}
		if(empty($innerhtml))
			return false;

		$offset = $i-1;
		$innerhtml = preg_replace('/^>+/m', '', $innerhtml);
		$innerhtml = preg_replace('/<br>>+/m', '<br>', $innerhtml);
		return '<blockquote>'.$innerhtml.'</blockquote>';
	}

	public function tableParser($text, &$offset) {
		$tableTable = array();
		$len = strlen($text);
		$lineStart = $offset;
		
		$tableInnerStr = '';
		$tableStyleList = array();
		for($i=$offset;$i<$len;$i=self::seekEndOfLine($text, $i)+1) {
			$now = self::getChar($text,$i);
			$eol = self::seekEndOfLine($text, $i);
			if(!self::startsWith($text, '||', $i)) {
				// table end
				break;
			}
			$line = substr($text, $i, $eol-$i);
			$td = explode('||', $line);
			$td_cnt = count($td);

			$trInnerStr = '';
			$simpleColspan = 0;
			for($j=1;$j<$td_cnt-1;$j++) {
				$innerstr = htmlspecialchars_decode($td[$j]);

				if($innerstr=='') {
					$simpleColspan += 1;
					continue;
				}

				$tdAttr = $tdStyleList = array();
				$trAttr = $trStyleList = array();
				
				if($simpleColspan != 0) {
					$tdAttr['colspan'] = $simpleColspan+1;
					$simpleColspan = 0;
				}
				while(self::startsWith($innerstr, '<')) {
					$dummy=0;
					$prop = $this->bracketParser($innerstr, $dummy, array('open'	=> '<', 'close' => '>','multiline' => false,'processor' => function($str) { return $str; }));
					$innerstr = substr($innerstr, $dummy+1);
					switch($prop) {
						case '(':
							break;
						case ':':
							$tdStyleList['text-align'] = 'center';
							break;
						case ')':
							$tdStyleList['text-align'] = 'right';
							break;
						default:
							if(self::startsWith($prop, 'table ')) {
								$tbprops = explode(' ', $prop);
								foreach($tbprops as $tbprop) {
									if(!preg_match('/^([^=]+)=(?|"(.*)"|\'(.*)\'|(.*))$/', $tbprop, $tbprop))
										continue;
									switch($tbprop[1]) {
										case 'align':
											switch($tbprop[2]) {
												case 'left':
													$tableStyleList['margin-left'] = null;
													$tableStyleList['margin-right'] = 'auto';
													break;
												case 'center':
													$tableStyleList['margin-left'] = 'auto';
													$tableStyleList['margin-right'] = 'auto';
													break;
												case 'right':
													$tableStyleList['margin-left'] = 'auto';
													$tableStyleList['margin-right'] = null;
													break;
											}
											break;
										case 'bgcolor':
											$tableStyleList['background-color'] = $tbprop[2];
											break;
										case 'bordercolor':
											$tableStyleList['border'] = '2px solid ';
											$tableStyleList['border'] .= $tbprop[2];
											break;
										case 'width':
											$tableStyleList['width'] = $tbprop[2];
											break;
									}
								}
							}
							elseif(preg_match('/^(\||\-|v|\^)\|?([0-9]+)$/', $prop, $span)) {
								if($span[1] == '-') {
									$tdAttr['colspan'] = $span[2];
									break;
								}
								elseif($span[1] == '|') {
									$tdAttr['rowspan'] = $span[2];
									break;
								}
								elseif($span[1] == '^') {
									$tdAttr['rowspan'] = $span[2];
									$tdStyleList['vertical-align'] = 'top';
									break;
								}
								elseif($span[1] == 'v') {
									$tdAttr['rowspan'] = $span[2];
									$tdStyleList['vertical-align'] = 'bottom';
									break;
								}
							}
							elseif(preg_match('/^#(?:([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})|([A-Za-z]+))$/', $prop, $span)) {
								$tdStyleList['background-color'] = $span[1]?'#'.$span[1]:$span[2];
								break;
							}
							elseif(preg_match('/^([^=]+)=(?|"(.*)"|\'(.*)\'|(.*))$/', $prop, $match)) {
								switch($match[1]) {
									case 'bgcolor':
										$tdStyleList['background-color'] = $match[2];
										break;
									case 'rowbgcolor':
										$trStyleList['background-color'] = $match[2];
									case 'width':
										$tdStyleList['width'] = $match[2];
										break;
									case 'height':
										$tdStyleList['height'] = $match[2];
										break;
								}
							}
					}
				}

				if(empty($tdStyleList['text-align'])) {
					if(preg_match('/^ .* $/', $innerstr)) {
						$tdStyleList['text-align'] = 'center';
					}
					elseif(self::seekEndOfLine($innerstr)>0 && $innerstr[self::seekEndOfLine($innerstr)-1] == ' ') {
						$tdStyleList['text-align'] = null;
					}
					elseif(self::startsWith($innerstr, ' ')) {
						$tdStyleList['text-align'] = 'right';
					}
					else {
						$tdStyleList['text-align'] = null;
					}
				}
				$innerstr = trim($innerstr);
				
				$tdAttr['style'] = '';
				foreach($tdStyleList as $styleName =>$tdstyleValue) {
					if(empty($tdstyleValue))
						continue;
					$tdAttr['style'] .= $styleName.': '.$tdstyleValue.'; ';
				}
				
				$trAttr['style'] = '';
				foreach($trStyleList as $styleName =>$trstyleValue) {
					if(empty($trstyleValue))
						continue;
					$trAttr['style'] .= $styleName.': '.$trstyleValue.'; ';
				}

				$tdAttrStr = '';
				foreach($tdAttr as $propName => $propValue) {
					if(empty($propValue))
						continue;
					$tdAttrStr .= ' '.$propName.'="'.str_replace('"', '\\"', $propValue).'"';
				}
				
				if (!isset($trAttrStri)) {
					$trAttrStri = true;
					$trAttrStr = '';
					foreach($trAttr as $propName => $propValue) {
						if(empty($propValue))
							continue;
						$trAttrStr .= ' '.$propName.'="'.str_replace('"', '\\"', $propValue).'"';
					}
				}
				
				$trInnerStr .= '<td'.$tdAttrStr.'>'.$this->blockParser($innerstr).'</td>';
			}
			$tableInnerStr .= !empty($trInnerStr)?'<tr'.$trAttrStr.'>'.$trInnerStr.'</tr>':'';
			unset($trAttrStri);
		}

		if(empty($tableInnerStr))
			return false;

		$tableStyleStr = '';
		foreach($tableStyleList as $styleName =>$styleValue) {
			if(empty($styleValue))
				continue;
			$tableStyleStr .= $styleName.': '.$styleValue.'; ';
		}

		$tableAttrStr = ($tableStyleStr?' style="'.$tableStyleStr.'"':'');
		$result = '<table class="wikitable"'.$tableAttrStr.'>'.$tableInnerStr."</table>\n";
		$offset = $i-1;
		return $result;
	}

	private function listParser($text, &$offset) {
		$listTable = array();
		$len = strlen($text);
		$lineStart = $offset;

		$quit = false;
		for($i=$offset;$i<$len;$before=self::nextChar($text,$i)) {
			$now = self::getChar($text,$i);
			if($now != ' ') {
				if($lineStart == $i) {
					// list end
					break;
				}

				$match = false;

				foreach($this->list_tag as $list_tag) {
					if(self::startsWith($text, $list_tag[0], $i)) {

						if(!empty($listTable[0]) && $listTable[0]['tag']=='indent') {
							$i = $lineStart;
							$quit = true;
							break;
						}

						$eol = self::seekEndOfLine($text, $lineStart);
						$tlen = strlen($list_tag[0]);
						$innerstr = substr($text, $i+$tlen, $eol-($i+$tlen));
						$this->listInsert($listTable, $innerstr, ($i-$lineStart), $list_tag[1]);
						$i = $eol;
						$now = "\n";
						$match = true;
						break;
					}
				}
				if($quit)
					break;

				if(!$match) {
					// indent
					if(!empty($listTable[0]) && $listTable[0]['tag']!='indent') {
						$i = $lineStart;
						break;
					}

					$eol = self::seekEndOfLine($text, $lineStart);
					$innerstr = substr($text, $i, $eol-$i);
					$this->listInsert($listTable, $innerstr, ($i-$lineStart), 'indent');
					$i = $eol;
					$now = "\n";
				}
			}
			if($now == "\n") {
				$lineStart = $i+1;
			}
		}
		if(!empty($listTable[0])) {
			$offset = $i-1;
			return $this->listDraw($listTable);
		}
		return false;
	}

	private function listInsert(&$arr, $text, $level, $tag) {
		if(preg_match('/^#([1-9][0-9]*) /', $text, $start))
			$start = $start[1];
		else
			$start = 1;
		if(empty($arr[0])) {
			$arr[0] = array('text' => $text, 'start' => $start, 'level' => $level, 'tag' => $tag, 'childNodes' => array());
			return true;
		}

		$last = count($arr)-1;
		$readableId = $last+1;
		if($arr[0]['level'] >= $level) {
			$arr[] = array('text' => $text, 'start' => $start, 'level' => $level, 'tag' => $tag, 'childNodes' => array());
			return true;
		}
		
		return $this->listInsert($arr[$last]['childNodes'], $text, $level, $tag);
	}

	private function listDraw($arr) {
		if(empty($arr[0]))
			return '';

		$tag = $arr[0]['tag'];
		$start = $arr[0]['start'];
		$result = '<'.($tag=='indent'?'div class="indent"':$tag.($start!=1?' start="'.$start.'"':'')).'>';
		foreach($arr as $li) {
			$text = $this->blockParser($li['text']).$this->listDraw($li['childNodes']);
			$result .= $tag=='indent'?$text:'<li>'.$text.'</li>';
		}
		$result .= '</'.($tag=='indent'?'div':$tag).'>';
		$result .= "\n";
		return $result;
	}

	public function lineParser($line, $type) {
		$result = '';

		$line = $this->blockParser($line);

		if($type == 'notn') {
			return $line;
		} else {
		return $line."\n";
		}
	}

	private function blockParser($block) {
		$result = '';
		$block_len = strlen($block);
		
		if(preg_match('/^#title (.*)$/', $block, $match)) {
			$result .= ''
				.'{{DISPLAYTITLE:'.$match[1].'}}'
				.'';
			$block = '';
		}
		
		if(preg_match('/^(.*?)(?<!<nowiki>)attachment:"?(.*?)(\.jpeg|\.jpg|\.png|\.gif)"?([?&][^\| <]+)?(?!<\/nowiki>)(.*)$/i', $block, $match)) {
			
			$match[2] = preg_replace('/^\//', '', $match[2]);
			
			if(strlen($match[4]) == 0) {
				$result .= ''
					.$match[1].'[[파일:'.$match[2].$match[3].']]'
					.'';
					
			} elseif(preg_match('/%/', $match[4])) {
				$vowels = array('?', '&');
				$match[4] = str_replace($vowels, '|', $match[4]);
				$match[4] = str_replace('%', '', $match[4]);
				$match[4] = str_replace('width', 'newwidth', $match[4]);
				$match[4] = str_replace('align', 'caption', $match[4]);
				return '{{ScaleImage|imagename='.$match[2].$match[3].$match[4].'}}';
			} else {
				$vowels = array('?', '&');
				$match[4] = str_replace($vowels, '|', $match[4]);
				$match[4] = preg_replace('/width=(\d*)/i', '$1px', $match[4]);
				$match[4] = str_replace('align=', '', $match[4]);
				
				$result .= ''
					.$match[1].'[[파일:'.$match[2].$match[3].$match[4].']]'
					.'';
					
			}
			
			$block = $this->blockParser($match[5]);
		}
		
		$result .= $this->formatParser($block);
		return $result;
	}

	private function bracketParser($text, &$now, $bracket) {
		$len = strlen($text);
		$cnt = 0;
		$done = false;

		$openlen = strlen($bracket['open']);
		$closelen = strlen($bracket['close']);

		for($i=$now;$i<$len;self::nextChar($text,$i)) {
			if(self::startsWith($text, $bracket['open'], $i) && !($bracket['open']==$bracket['close'] && $cnt>0)) {
				$cnt++;
				$done = true;
				$i+=$openlen-1; // 반복될 때 더해질 것이므로
			}elseif(self::startsWith($text, $bracket['close'], $i)) {
				$cnt--;
				$i+=$closelen-1;
			}elseif(!$bracket['multiline'] && $text[$i] == "\n")
				return false;

			if($cnt == 0 && $done) {
				$innerstr = substr($text, $now+$openlen, $i-$now-($openlen+$closelen)+1);

				if((!strlen($innerstr)) ||($bracket['multiline'] && strpos($innerstr, "\n")===false))
					return false;
				$result = call_user_func_array($bracket['processor'],array($innerstr, $bracket['open']));
				$now = $i;
				return $result;
			}
		}
		return false;
	}

	private function formatParser($line) {
		$line_len = strlen($line);
		for($j=0;$j<$line_len;self::nextChar($line,$j)) {
			foreach($this->single_bracket as $bracket) {
				$nj=$j;
				if(self::startsWith($line, $bracket['open'], $j) && $innerstr = $this->bracketParser($line, $nj, $bracket)) {
					$line = substr($line, 0, $j).$innerstr.substr($line, $nj+1);
					$line_len = strlen($line);
					$j+=strlen($innerstr)-1;
					break;
				}
			}
		}
		return $line;
	}

	private function renderProcessor($text, $type) {
		if(self::startsWithi($text, '#!html')) {
			$text = substr($text, 7);
			return '<html>'.$text.'</html>';
		} elseif($type == '{{|') {
			if(preg_match('/\|-/', $text)) {
				return $type.$text.$type;
			} else {
				if(preg_match('/<#(.*?)>/', $text, $color)) {
					$text = str_replace($color[0], '', $text);
					return '<poem style="border: 2px solid #d6d2c5; background-color: #'.$color[1].'; padding: 1em;">'.$text.'</poem>';
				} elseif(preg_match('/<tablewidth=(.*?)>/', $text, $width)) {
					$text = str_replace($width[0], '', $text);
					return '<poem style="border: 2px solid #d6d2c5; background-color: #f9f4e6; width: '.$width[1].'; padding: 1em;">'.$text.'</poem>';
				} else {
				return '<poem style="border: 2px solid #d6d2c5; background-color: #f9f4e6; padding: 1em;">'.$text.'</poem>';
				}
			}
		}
		
		$vowels = array('{{{#!html', '}}}');
		$rpe = array("<html><xmp>", '</xmp></html>');
		$text = str_ireplace($vowels, $rpe, $text);
		return '<pre>'.$text.'</pre>';
	}

	private function linkProcessor($text, $type) {
		if(preg_match('/^br$/im', $text)) {
			return '<br>';
		} elseif(preg_match('/^목차$/m', $text)) {
			return '__TOC__';
		} elseif(preg_match('/^각주$/m', $text)) {
			return '<references />';
		} elseif(self::startsWithi($text, 'wiki')) {
			if(preg_match('/wiki: ?"(.*?)" ?(.*)/', $text, $wikilinks)) {
				if(preg_match('/https?.*?(\.jpeg|\.jpg|\.png|\.gif)/' ,$wikilinks[2])) {
					$wikilinks[2] = '<html><img src="'.$wikilinks[2].'"></html>';
				}

				return '[['.$wikilinks[1].'|'.$wikilinks[2].']]';
			}	
		} elseif(preg_match('/^"(.*?)" ?(.*)/m', $text, $wikilinks)) {
			return '[['.$wikilinks[1].'|'.$wikilinks[2].']]';
		} elseif(self::startsWithi($text, 'include') && preg_match('/^include\((.+)\)$/i', $text, $include)) {
			return '{{'.$include[1].'}}'."\n";
		} elseif(preg_match('/youtube\((.*)\)/', $text, $youtube_code)) {
			$youtube_code[1] = preg_replace('/,(.*)/', '', $youtube_code[1]);
			return '{{#ev:youtube|'.$youtube_code[1].'}}';
		} elseif(preg_match('/nicovideo\((.*)\)/', $text, $nico_code)) {
			return '{{#ev:nico|'.$nico_code[1].'}}';
		} elseif(preg_match('/anchor\((.*?)\)/i', $text, $anchor)) {
			return '<span id="'.$anchor[1].'"></span>';
		} elseif(self::startsWith($text, 'http')) {
			$text = str_replace('|', ' ', $text);
			return '['.$text.']';
		} elseif(self::startsWithi($text, 'html(')) {
			$html = $text;
			$html = preg_replace('/html\((.*)\)/i', '$1', $html);
			$html = htmlspecialchars_decode($html);
			return '<html>'.$html.'</html>';
		}
		return '[['.$this->formatParser($text).']]';
	}

	private function macroProcessor($text, $type) {
		$text = $this->formatParser($text);
		switch(strtolower($text)) {
			case 'br':
				return '<br>';
			case 'date':
				return date('Y-m-d H:i:s');
			case '목차':
			case 'tableofcontents':
				return '__TOC__';
			case '각주':
			case 'footnote':
				return '<references />';
			default:
				if(self::startsWithi($text, 'include') && preg_match('/^include\((.+)\)$/i', $text, $include)) {
					$include[1] = str_replace(',', '|', $include[1]);
					$include[1] = urldecode($include[1]);
					return '{{'.$include[1].'}}'."\n";
				} elseif(self::startsWith($text, '*') && preg_match('/^\*([^ ]*)([ ].+)?$/', $text, $note)) {
					if(isset($note[2])) {
						return "<ref>$note[2]</ref>";
					}
				} elseif(preg_match('/youtube\((.*)\)/', $text, $youtube_code)) {
					$youtube_code[1] = preg_replace('/,(.*)/', '', $youtube_code[1]);
					return '{{#ev:youtube|'.$youtube_code[1].'}}';
				} elseif(preg_match('/nicovideo\((.*)\)/', $text, $nico_code)) {
					return '{{#ev:nico|'.$nico_code[1].'}}';
				} elseif(preg_match('/wiki: ?"(.*?)" ?(.*)/', $text, $wikilinks) || preg_match('/^"(.*?)" ?(.*)/m', $text, $wikilinks) || preg_match('/wiki:(\w*?) (.*)/u', $text, $wikilinks) || preg_match('/^wiki:(.*)/', $text, $wikilinks)) {
					if(isset($wikilinks[2]) && $wikilinks[2] !== '') {
					return '[['.$wikilinks[1].'|'.$wikilinks[2].']]';
					} else {
					return '[['.$wikilinks[1].']]';
					}
				} elseif(!self::startsWith($text, '[') && !preg_match('/^https?/m', $text)) {
					return '[['.$text.']]';
				} 

		}
		$text = str_replace('|', ' ', $text);
		return '['.$text.']';
	}

	private function textProcessor($otext, $type) {
		if($type != '{{{' && $type != '<nowiki>')
			$text = $this->formatParser($otext);
		else
			$text = $otext;
		switch($type) {
			case '--':
			case '~~':
				return '<s>'.$text.'</s>';
			 case '__':
				if(preg_match('/TOC/', $text)) {
					return '__'.$text.'__';
				} else {
					return '<u>'.$text.'</u>';
				}
			case '^^':
				return '<sup>'.$text.'</sup>';
			case ',,':
				return '<sub>'.$text.'</sub>';
			case '$ ':
				return '<math>'.$text.'</math>';
			case '<!--':
				return '<!--'.$text.'-->';
			case '{{|':
				return '<poem style="border: 2px solid #d6d2c5; background-color: #f9f4e6; padding: 1em;">'.$text.'</poem>';
			case '<nowiki>':
				return '<nowiki>'.$text.'</nowiki>';
			case '{{{':
				if(self::startsWith($text, '#!html')) {
					$html = substr($text, 6);
					$html = htmlspecialchars_decode($html);
					return '<html>'.$html.'</html>';
				}
				if(preg_match('/^#(?:([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})|([A-Za-z]+)) (.*)$/', $text, $color)) {
					if(empty($color[1]) && empty($color[2]))
						return $text;
					return '<span style="color: '.(empty($color[1])?$color[2]:'#'.$color[1]).'">'.$this->formatParser($color[3]).'</span>';
				}
				if(preg_match('/^\+([1-5]) (.*)$/', $text, $size)) {
					for ($i=1; $i<=$size[1]; $i++){
						if(isset($big_before) && isset($big_after)) {
							$big_before .= '<big>';
							$big_after .= '</big>';
						} else {
							$big_before = '<big>';
							$big_after = '</big>';
						}
					}
					
					return $big_before.$this->formatParser($size[2]).$big_after;
				}
				if(preg_match('/^\-([1-5]) (.*)$/', $text, $size)) {
					for ($i=1; $i<=$size[1]; $i++){
						if(isset($small_before) && isset($small_after)) {
							$small_before .= '<small>';
							$small_after .= '</small>';
						} else {
							$small_before = '<small>';
							$small_after = '</small>';
						}
					}
					
					return $small_before.$this->formatParser($size[2]).$small_after;
				}
				
				return '<nowiki>'.$text.'</nowiki>';
		}
		return $type.$text.$type;
	}

	private function fnInsert(&$arr, &$text, $id = null) {
		$arr_cnt = count($arr);
		if(empty($id)) {
			$multi = false;
			$id = ++$this->fn_cnt;
		}
		else {
			$multi = true;
			for($i=0;$i<$arr_cnt;$i++) {
				if($arr[$i]['id']==$id) {
					$arr[$i]['count']++;
					$trim_data = trim($text);
					if(!empty($trim_data))
						$arr[$i]['text'] = $text;
					else
						$text = $arr[$i]['text'];
					return $id.'-'.$arr[$i]['count'];
				}
			}
		}
		$arr[] = array('id' => $id, 'text' => $text, 'count' => 1);
		return $multi?$id.'-1':$id;
	}
	
	private function tocInsert(&$arr, $text, $level, $path = '') {
		if(empty($arr[0])) {
			$arr[0] = array('name' => $text, 'level' => $level, 'childNodes' => array());
			return $path.'1';
		}

		$last = count($arr)-1;
		$readableId = $last+1;
		if($arr[0]['level'] >= $level) {
			$arr[] = array('name' => $text, 'level' => $level, 'childNodes' => array());
			return $path.($readableId+1);
		}
		
		return $this->tocInsert($arr[$last]['childNodes'], $text, $level, $path.$readableId.'.');
	}

	private function hParse(&$text) {
		$lines = explode("\n", $text);
		$result = '';
		foreach($lines as $line) {
			$matched = false;

			foreach($this->h_tag as $tag_ar) {
				$tag = $tag_ar[0];
				$level = $tag_ar[1];
				if(!empty($tag) && preg_match($tag, $line, $match)) {
					$this->tocInsert($this->toc, $this->blockParser($match[1]), $level);
					$matched = true;
					break;
				}
			}
		}

		return $result;
	}

	private static function getChar($string, $pointer){
		if(!isset($string[$pointer])) return false;
		$char = ord($string[$pointer]);
		if($char < 128){
			return $string[$pointer];
		}else{
			if($char < 224){
				$bytes = 2;
			}elseif($char < 240){
				$bytes = 3;
			}elseif($char < 248){
				$bytes = 4;
			}elseif($char == 252){
				$bytes = 5;
			}else{
				$bytes = 6;
			}
			$str = substr($string, $pointer, $bytes);
			return $str;
		}
	}

	private static function nextChar($string, &$pointer){
		if(!isset($string[$pointer])) return false;
		$char = ord($string[$pointer]);
		if($char < 128){
			return $string[$pointer++];
		}else{
			if($char < 224){
				$bytes = 2;
			}elseif($char < 240){
				$bytes = 3;
			}elseif($char < 248){
				$bytes = 4;
			}elseif($char == 252){
				$bytes = 5;
			}else{
				$bytes = 6;
			}
			$str = substr($string, $pointer, $bytes);
			$pointer += $bytes;
			return $str;
		}
	}

	private static function startsWith($haystack, $needle, $offset = 0) {
		$len = strlen($needle);
		if(($offset+$len)>strlen($haystack))
			return false;
		return $needle == substr($haystack, $offset, $len);
	}

	private static function startsWithi($haystack, $needle, $offset = 0) {
		$len = strlen($needle);
		if(($offset+$len)>strlen($haystack))
			return false;
		return strtolower($needle) == strtolower(substr($haystack, $offset, $len));
	}

	private static function seekEndOfLine($text, $offset=0) {
		return ($r=strpos($text, "\n", $offset))===false?strlen($text):$r;
	}
}