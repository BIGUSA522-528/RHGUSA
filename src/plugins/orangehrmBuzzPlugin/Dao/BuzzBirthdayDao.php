<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software: you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with OrangeHRM.
 * If not, see <https://www.gnu.org/licenses/>.
 */
namespace OrangeHRM\Buzz\Dao;
use OrangeHRM\Core\Dao\BaseDao;
use OrangeHRM\Entity\Employee;
use OrangeHRM\ORM\Paginator;
use OrangeHRM\Buzz\Dto\EmployeeBirthdaySearchFilterParams;
class BuzzBirthdayDao extends BaseDao
{
    /**
     * @param EmployeeBirthdaySearchFilterParams $employeeBirthdaySearchFilterParams
     * @return array
     */
    public function getUpcomingBirthdaysList(
        EmployeeBirthdaySearchFilterParams $employeeBirthdaySearchFilterParams
    ): array {
        return $this->getUpcomingBirthdaysPaginator($employeeBirthdaySearchFilterParams)->getQuery()->execute();
    }
    /**
     * @param EmployeeBirthdaySearchFilterParams $employeeBirthdaySearchFilterParams
     * @return Paginator
     */
    private function getUpcomingBirthdaysPaginator(
        EmployeeBirthdaySearchFilterParams $employeeBirthdaySearchFilterParams
    ): Paginator {
        $q = $this->createQueryBuilder(Employee::class, 'employee');
        $this->setSortingAndPaginationParams($q, $employeeBirthdaySearchFilterParams);
        $thisYearDateDiff = 'DATE_DIFF(:nextDate, CONCAT(:thisYear, SUBSTRING(employee.birthday,5,6)))';
        $orExpr = $q->expr()->orX(
            $q->expr()->between($thisYearDateDiff, ':dateDiffMin', ':dateDiffMax')
        );
        if (($nextYear = $employeeBirthdaySearchFilterParams->getNextDate()->format('Y'))
            != $employeeBirthdaySearchFilterParams->getThisYear()
        ) {
            $nextYearDateDiff = 'DATE_DIFF(:nextDate, CONCAT(:nextYear, SUBSTRING(employee.birthday,5,6)))';
            $orExpr->add(
                $q->expr()->between($nextYearDateDiff, ':dateDiffMin', ':dateDiffMax')
            );
            $q->setParameter('nextYear', $nextYear);
            $q->addOrderBy(
                "CASE WHEN $thisYearDateDiff BETWEEN :dateDiffMin AND :dateDiffMax
                    THEN $thisYearDateDiff
                    ELSE $nextYearDateDiff
                END",
                'DESC'
            );
        } else {
            $q->addOrderBy($thisYearDateDiff, 'DESC');
        }
        $q->andWhere($orExpr)
            ->setParameter('thisYear', $employeeBirthdaySearchFilterParams->getThisYear())
            ->setParameter('nextDate', $employeeBirthdaySearchFilterParams->getNextDate())
            ->setParameter('dateDiffMin', $employeeBirthdaySearchFilterParams->getDateDiffMin())
            ->setParameter('dateDiffMax', $employeeBirthdaySearchFilterParams->getDateDiffMax());
        $q->andWhere($q->expr()->isNotNull('employee.birthday'));
        $q->andWhere($q->expr()->isNull('employee.employeeTerminationRecord'));
        $q->andWhere($q->expr()->isNull('employee.purgedAt'));
        return $this->getPaginator($q);
    }
    /**
     * @param EmployeeBirthdaySearchFilterParams $employeeBirthdaySearchFilterParams
     * @return int
     */
    public function getUpcomingBirthdaysCount(
        EmployeeBirthdaySearchFilterParams $employeeBirthdaySearchFilterParams
    ): int {
        return $this->getUpcomingBirthdaysPaginator($employeeBirthdaySearchFilterParams)->count();
    }
}
