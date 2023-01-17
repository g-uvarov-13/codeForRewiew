<?php
require_once 'config.php';

use SiteCore\Core;
use SiteCore\HeadHunterIntegration\HeadHunterRequests;
use SiteCore\HeadHunterIntegration\HeadHunterService;
use SiteCore\Entity\HeadHunterVacanciesTable;
class CollectHeadHunterVacancies
{
    private $vacancyRequest;
    private $table;

    public function __construct()
    {
        $this->vacancyRequest = new HeadHunterRequests();
        $this->table = new HeadHunterService();
    }

    /**
     * @param $employerId
     * @return array
     * получаем все вакансии по employer_id
     */
    public function getAllVacancies($employerId)
    {
        $allVacancies = [];
        $vacanciesDetailRequests = [];
        $allDetailVacancies = [];
        // Получаем первую страницу, с максимально возможным числом вакансий на ней
        $allVacancies[0] = $this->vacancyRequest->getCurl('https://api.hh.ru/vacancies?employer_id=' . $employerId . '&per_page=100');
        // Проверяем, если кол-во страниц больше одной, шлём запросы и собираем вакансии со всех возможных страниц
        if ($allVacancies[0]['pages'] > 1) {
            for ($index = 0; $index <= $allVacancies[0]['pages']; $index++) {
                $allVacancies[$index] = $this->vacancyRequest->getCurl('https://api.hh.ru/vacancies?employer_id=' . $employerId . '&per_page=100&page=' . $index);
            }
        }
        //Получаем все вакансии компании
        foreach ($allVacancies as $page) {
            foreach ($page['items'] as $vacancy) {
                $vacanciesDetailRequests[] = 'https://api.hh.ru/vacancies/' . $vacancy['id'];
            }
        }
        // получаем все детальные вакансии
        foreach ($vacanciesDetailRequests as $DetailRequest) {
            $allDetailVacancies[] = $this->vacancyRequest->getCurl($DetailRequest);
        }
        return $allDetailVacancies;
    }

    /**
     * Выбираем только нужные поля для добавления в таблицу
     * @param $allVacancies
     * @param $workPlace
     * @return array
     */
    public function prepareVacancyInfo($allVacancies, $workPlace)
    {
        $formattedInfo = [];
        if (!empty($allVacancies)) {
            foreach ($allVacancies as $detailVacancy) {
                $formattedInfo['VACANCIES'][] = [
                    'VACANCY_ID' => $detailVacancy['id'],
                    'VACANCY_NAME' => $detailVacancy['name'],
                    'VACANCY_CITY' => $detailVacancy['area']['name'],
                    'VACANCY_SALARY_FROM' => $detailVacancy['salary']['from'],
                    'VACANCY_SALARY_TO' => $detailVacancy['salary']['to'],
                    'VACANCY_SCHEDULE' => $detailVacancy['schedule']['name'],
                    'VACANCY_EXPERIENCE' => $detailVacancy['experience']['name'],
                    'VACANCY_SKILLS' => json_encode($detailVacancy['key_skills']),
                    'VACANCY_DESCRIPTION' => json_encode($detailVacancy['description']),
                    'VACANCY_PROFESSIONAL_ROLE' => $detailVacancy['professional_roles'][0]['name'],
                    'VACANCY_URL_TO_APPLY_FOR_JOB' => $detailVacancy['apply_alternate_url'],
                    'VACANCY_ADDRESS' => json_encode($detailVacancy['address']),
                    'VACANCY_CONTACTS' => json_encode($detailVacancy['contacts']),
                    'VACANCY_WORKPLACE' => $workPlace,
                    'VACANCY_PUBLISHED_DATE' => FormatDate('d F Y', strtotime($detailVacancy['published_at'])),
                ];
                $formattedInfo['IDS_FOR_FILTER'][] = $detailVacancy['id'];
            }
            return $formattedInfo;
        }
    }

    /**
     * Добавляем/обновляем ифнормацию по вакансиям в таблице
     * @param $vacancies
     */
    public function addToHeadHunterTable($vacancies)
    {
        if (!empty($vacancies['IDS_FOR_FILTER'])) {
            $filter['=VACANCY_ID'] = $vacancies['IDS_FOR_FILTER'];
            $tableVacancies = $this->table->getTableVacanciesFilter($filter);
        }
        foreach ($vacancies['VACANCIES'] as $vacancy) {
            if (!empty($tableVacancies[$vacancy['VACANCY_ID']])) { // update
                $this->table->updateTable($vacancy, $tableVacancies[$vacancy['VACANCY_ID']]['ID']);
            } else { //add
                $this->table->addToTable($vacancy);
            }
        }
    }

    /**
     * Удаление архивных вакансий
     * @param $newVacancies
     */
    public function deleteOldVacancies($newVacancies)
    {
        $allTableVacancies = HeadHunterVacanciesTable::getList([
            'select' => [
                'ID', 'VACANCY_ID'
            ]
        ])->fetchAll();
        foreach ($allTableVacancies as $allTableVacancy) {
            if (!in_array($allTableVacancy['VACANCY_ID'], $newVacancies)) {
                $vacanciesIdToDelete[] = $allTableVacancy['ID'];
            }
        }
        if (!empty($vacanciesIdToDelete)) {
            foreach ($vacanciesIdToDelete as $vacancyIdToDelete) {
                HeadHunterVacanciesTable::delete($vacancyIdToDelete);
            }
        }

    }
}

$headHunter = new CollectHeadHunterVacancies();

//Получение всех вакансий для аккаунта Магазин 
$allInfoVacanciesStore = $headHunter->getAllVacancies(CORE::HH_API_SHOP);
$formattedInfoVacanciesStore = $headHunter->prepareVacancyInfo($allInfoVacanciesStore, 'Магазин');
if (!empty($formattedInfoVacanciesStore)) {
    $headHunter->addToHeadHunterTable($formattedInfoVacanciesStore);
}

//Получение всех вакансий для аккаунта Офис 
$allInfoVacanciesOffice = $headHunter->getAllVacancies(CORE::HH_API_OFFICE);
$formattedInfoVacanciesOffice = $headHunter->prepareVacancyInfo($allInfoVacanciesOffice, 'Офис');
if (!empty($formattedInfoVacanciesOffice)) {
    $headHunter->addToHeadHunterTable($formattedInfoVacanciesOffice);
}

//Собираем все прилетевшие вакансии с HH
$allReceivedVacancies = array_merge($formattedInfoVacanciesStore['IDS_FOR_FILTER'], $formattedInfoVacanciesOffice['IDS_FOR_FILTER']);
$headHunter->deleteOldVacancies($allReceivedVacancies);
