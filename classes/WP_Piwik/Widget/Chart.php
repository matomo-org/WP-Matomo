<?php

namespace WP_Piwik\Widget;

use WP_Piwik\Widget;

class Chart extends Visitors
{

    public $className = __CLASS__;

    public function show()
    {
        $result = $this->requestData();
        $response = $result["response"];
        if (!$result["success"]) {
			$message = '';
			foreach ($this->method as $m) {
				if (empty($response[$m]['message'])) {
					continue;
				}

				$message .= ' ' . $m . ' - ' . $response[$m]['message'];
			}
            echo '<strong>' . __('Piwik error', 'wp-piwik') . ':</strong> ' . htmlentities($message, ENT_QUOTES, 'utf-8');
        } else {
            $values = $labels = $bounced = $unique = '';
            $count = $uniqueSum = 0;
            if (is_array($response['VisitsSummary.getVisits']))
                foreach ($response['VisitsSummary.getVisits'] as $date => $value) {
                    $count++;
                    $values .= $value . ',';
                    $unique .= $response['VisitsSummary.getUniqueVisitors'][$date] . ',';
                    $bounced .= $response['VisitsSummary.getBounceCount'][$date] . ',';
                    if ($this->parameter['period'] == 'week') {
                        preg_match("/[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/", $date, $dateList);
                        $textKey = $this->dateFormat($dateList[0], 'short_week');
                    } else $textKey = substr($date, -2);
                    $labels .= '["' . $textKey . '"],';
                    $uniqueSum += $response['VisitsSummary.getActions'][$date];
                }
            else {
                $values = '0,';
                $labels = '[0,"-"],';
                $unique = '0,';
                $bounced = '0,';
            }
            $average = round($uniqueSum / 30, 0);
            $values = substr($values, 0, -1);
            $unique = substr($unique, 0, -1);
            $labels = substr($labels, 0, -1);
            $bounced = substr($bounced, 0, -1);
            ?>
            <div>
                <canvas id="wp-piwik_stats_vistors_graph" style="height:220px;"></canvas>
            </div>
            <script>
                new Chart(
                    document.getElementById('wp-piwik_stats_vistors_graph'),
                    {
                        type: 'line',
                        data: {
                            labels: [<?php echo $labels ?>],
                            datasets: [
                                {
                                    label: 'Visitors',
                                    backgroundColor: '#0277bd',
                                    borderColor: '#0277bd',
                                    data: [<?php echo $values; ?>],
                                    borderWidth: 1
                                },
                                {
                                    label: 'Unique',
                                    backgroundColor: '#ff8f00',
                                    borderColor: '#ff8f00',
                                    data: [<?php echo $unique; ?>],
                                    borderWidth: 1
                                },
                                {
                                    label: 'Bounced',
                                    backgroundColor: '#ad1457',
                                    borderColor: '#ad1457',
                                    data: [<?php echo $bounced; ?>],
                                    borderWidth: 1
                                },
                            ]
                        },
                        options: {}
                    }
                );
            </script>
            <?php
        }
    }

}
