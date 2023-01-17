<?php

namespace Sitecore\HeadHunterIntegration;

use SiteCore\Entity\HeadHunterVacanciesTable;
use Bitrix\Main\Type\DateTime;
use SiteCore\Core;
class HeadHunterService
{
    /**
     * @param $vacancyInfo
     * Добавление в таблицу вакансии
     */
    public function addToTable($vacancyInfo)
    {
        $result = HeadHunterVacanciesTable::add([
            'VACANCY_ID' => $vacancyInfo['VACANCY_ID'],
            'VACANCY_NAME' => $vacancyInfo['VACANCY_NAME'],
            'VACANCY_CITY' => $vacancyInfo['VACANCY_CITY'],
            'VACANCY_SALARY_FROM' => $vacancyInfo['VACANCY_SALARY_FROM'],
            'VACANCY_SALARY_TO' => $vacancyInfo['VACANCY_SALARY_TO'],
            'VACANCY_SCHEDULE' => $vacancyInfo['VACANCY_SCHEDULE'],
            'VACANCY_EXPERIENCE' => $vacancyInfo['VACANCY_EXPERIENCE'],
            'VACANCY_SKILLS' => $vacancyInfo['VACANCY_SKILLS'],
            'VACANCY_DESCRIPTION' => $vacancyInfo['VACANCY_DESCRIPTION'],
            'VACANCY_PROFESSIONAL_ROLE' => $vacancyInfo['VACANCY_PROFESSIONAL_ROLE'],
            'VACANCY_URL_TO_APPLY_FOR_JOB' => $vacancyInfo['VACANCY_URL_TO_APPLY_FOR_JOB'],
            'VACANCY_ADDRESS' => $vacancyInfo['VACANCY_ADDRESS'],
            'VACANCY_CONTACTS' => $vacancyInfo['VACANCY_CONTACTS'],
            'VACANCY_WORKPLACE' => $vacancyInfo['VACANCY_WORKPLACE'],
            'VACANCY_PUBLISHED_DATE' => $vacancyInfo['VACANCY_PUBLISHED_DATE'],
            'ENTRY_DATE' => new DateTime(),

        ]);
        if (!$result->isSuccess()) {
            if (\CModule::IncludeModule('logs')) {
                $logger = new Logs\Log('HH_error_addToTable');
                $logger->error($result->getErrorMessages());
            }
        }
    }

    /**
     * @param $vacancyInfo
     * @param $tableEntryId
     * Обновление вакансии в таблице
     */
    public function updateTable($vacancyInfo,$tableEntryId)
    {
        $result = HeadHunterVacanciesTable::update(
            $tableEntryId,
            [
                //'VACANCY_ID' => $vacancyInfo['STORE_ID'],
                'VACANCY_NAME' => $vacancyInfo['VACANCY_NAME'],
                'VACANCY_CITY' => $vacancyInfo['VACANCY_CITY'],
                'VACANCY_SALARY' => $vacancyInfo['VACANCY_SALARY'],
                'VACANCY_SCHEDULE' => $vacancyInfo['VACANCY_SCHEDULE'],
                'VACANCY_EXPERIENCE' => $vacancyInfo['VACANCY_EXPERIENCE'],
                'VACANCY_SKILLS' => $vacancyInfo['VACANCY_SKILLS'],
                'VACANCY_DESCRIPTION' => $vacancyInfo['VACANCY_DESCRIPTION'],
                'VACANCY_PROFESSIONAL_ROLE' => $vacancyInfo['VACANCY_PROFESSIONAL_ROLE'],
                'VACANCY_URL_TO_APPLY_FOR_JOB' => $vacancyInfo['VACANCY_URL_TO_APPLY_FOR_JOB'],
                'VACANCY_ADDRESS' => $vacancyInfo['VACANCY_ADDRESS'],
                'VACANCY_CONTACTS' => $vacancyInfo['VACANCY_CONTACTS'],
                'VACANCY_WORKPLACE' => $vacancyInfo['VACANCY_WORKPLACE'],
                'VACANCY_PUBLISHED_DATE' => $vacancyInfo['VACANCY_PUBLISHED_DATE'],
                'ENTRY_DATE' => new DateTime(),
            ]
        );
        if (!$result->isSuccess()) {
            if (\CModule::IncludeModule('logs')) {
                $logger = new Logs\Log('HH_error_updateTable');
                $logger->error($result->getErrorMessages());
            }
        }

    }

    /**
     * @param $filter
     * @return array
     * Получение вакансий
     */
    public function getTableVacanciesFilter($filter)
    {
        $vacanciesTable = [];
            $table = HeadHunterVacanciesTable::getList([
                'order' => ['VACANCY_PROFESSIONAL_ROLE' => 'asc'],
                'filter' =>  $filter,
                'select' => [
                    'ID', 'VACANCY_ID', 'VACANCY_NAME', 'VACANCY_CITY', 'VACANCY_SALARY_FROM','VACANCY_SALARY_TO',
                    'VACANCY_SCHEDULE', 'VACANCY_EXPERIENCE', 'VACANCY_SKILLS', 'VACANCY_DESCRIPTION',
                    'VACANCY_PROFESSIONAL_ROLE', 'VACANCY_URL_TO_APPLY_FOR_JOB', 'VACANCY_ADDRESS','VACANCY_CONTACTS','VACANCY_WORKPLACE',
                    'VACANCY_PUBLISHED_DATE'],
            ]);

        while ($arRes = $table->fetch()) {
            $vacanciesTable[$arRes['VACANCY_ID']] = $arRes;
        }
        return $vacanciesTable;
    }

}
