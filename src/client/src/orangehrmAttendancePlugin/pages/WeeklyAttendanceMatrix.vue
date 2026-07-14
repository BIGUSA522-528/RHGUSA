<!--
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
 -->

<template>
  <div class="orangehrm-weekly-attendance-matrix">
    <oxd-table-filter filter-title="Matriz Semanal de Asistencia">
      <oxd-form @submit-valid="handleSubmit">
        <oxd-form-row>
          <oxd-grid :cols="4" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <employee-autocomplete
                v-model="filters.employee"
                :rules="rules.employee"
                :params="{
                  includeEmployees: 'currentAndPast',
                }"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.location"
                type="select"
                :label="$t('general.location')"
                :options="locations"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.subunit"
                type="select"
                :label="$t('general.sub_unit')"
                :options="subunits"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <date-input
                v-model="filters.fromDate"
                :placeholder="$t('general.from')"
                :rules="rules.fromDate"
                :label="$t('general.date_range')"
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <date-input
                v-model="filters.toDate"
                label="&nbsp"
                :placeholder="$t('general.to')"
                :rules="rules.toDate"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>

        <oxd-divider />

        <oxd-form-actions>
          <oxd-button
            type="submit"
            display-type="ghost"
            :is-loading="isExporting"
            :label="$t('general.download')"
            @click="pendingAction = 'export'"
          />
          <oxd-button
            type="submit"
            display-type="secondary"
            :is-loading="isLoading"
            :label="$t('general.view')"
            @click="pendingAction = 'view'"
          />
        </oxd-form-actions>
      </oxd-form>
    </oxd-table-filter>

    <div v-if="loaded" class="orangehrm-weekly-matrix-table-wrapper">
      <p v-if="employees.length === 0" class="orangehrm-weekly-matrix-empty">
        No se encontraron registros para el rango seleccionado.
      </p>
      <table v-else class="orangehrm-weekly-matrix-table">
        <thead>
          <tr>
            <th>Employee ID</th>
            <th>Nombre</th>
            <th>Departamento</th>
            <th>Ubicación</th>
            <th v-for="day in dayColumns" :key="day.date">
              {{ day.label }}<br />
              {{ day.dateLabel }}
            </th>
            <th>Total Horas</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="employee in employees" :key="employee.empNumber">
            <td>{{ employee.employeeId }}</td>
            <td>{{ employee.employeeName }}</td>
            <td>{{ employee.department || '-' }}</td>
            <td>{{ employee.location || '-' }}</td>
            <td
              v-for="day in dayColumns"
              :key="day.date"
              class="orangehrm-weekly-matrix-day-cell"
            >
              {{ employee.dates[day.date] || '-' }}
            </td>
            <td>{{ employee.totalHoursWeek }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import {ref} from 'vue';
import {
  validDateFormat,
  endDateShouldBeAfterStartDate,
  startDateShouldBeBeforeEndDate,
  shouldNotExceedCharLength,
  validSelection,
} from '@/core/util/validation/rules';
import EmployeeAutocomplete from '@/core/components/inputs/EmployeeAutocomplete';
import usei18n from '@/core/util/composable/usei18n';
import useDateFormat from '@/core/util/composable/useDateFormat';
import useToast from '@/core/util/composable/useToast';
import {APIService} from '@/core/util/services/api.service';

const DAY_NAMES = [
  'Domingo',
  'Lunes',
  'Martes',
  'Miércoles',
  'Jueves',
  'Viernes',
  'Sábado',
];

const defaultFilters = {
  employee: null,
  location: null,
  subunit: null,
  fromDate: null,
  toDate: null,
};

export default {
  components: {
    'employee-autocomplete': EmployeeAutocomplete,
  },

  props: {
    subunits: {
      type: Array,
      default: () => [],
    },
    locations: {
      type: Array,
      default: () => [],
    },
  },

  setup() {
    const {$t} = usei18n();
    const {userDateFormat} = useDateFormat();
    const {error: showErrorToast} = useToast();

    const filters = ref({...defaultFilters});
    const pendingAction = ref('view');
    const isLoading = ref(false);
    const isExporting = ref(false);
    const loaded = ref(false);
    const employees = ref([]);
    const dayColumns = ref([]);

    const matrixHttp = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/attendance/weekly-matrix',
    );

    const rules = {
      employee: [shouldNotExceedCharLength(100), validSelection],
      fromDate: [
        validDateFormat(userDateFormat),
        startDateShouldBeBeforeEndDate(
          () => filters.value.toDate,
          $t('general.from_date_should_be_before_to_date'),
          {allowSameDate: true},
        ),
      ],
      toDate: [
        validDateFormat(userDateFormat),
        endDateShouldBeAfterStartDate(
          () => filters.value.fromDate,
          $t('general.to_date_should_be_after_from_date'),
          {allowSameDate: true},
        ),
      ],
    };

    const buildDayColumns = (fromDate, toDate) => {
      const columns = [];
      const current = new Date(`${fromDate}T00:00:00`);
      const end = new Date(`${toDate}T00:00:00`);
      while (current <= end) {
        const iso = current.toISOString().slice(0, 10);
        const [, month, day] = iso.split('-');
        columns.push({
          date: iso,
          label: DAY_NAMES[current.getDay()],
          dateLabel: `${day}/${month}`,
        });
        current.setDate(current.getDate() + 1);
      }
      return columns;
    };

    const serializedFilters = () => ({
      fromDate: filters.value.fromDate,
      toDate: filters.value.toDate,
      locationId: filters.value.location?.id,
      subunitId: filters.value.subunit?.id,
      empNumber: filters.value.employee?.id,
    });

    const loadMatrix = async () => {
      isLoading.value = true;
      try {
        const response = await matrixHttp.getAll(serializedFilters());
        const data = response.data?.data ?? {};
        employees.value = data.employees ?? [];
        dayColumns.value = buildDayColumns(data.fromDate, data.toDate);
        loaded.value = true;
      } catch (e) {
        showErrorToast({
          title: $t('general.error'),
          message: $t('general.unexpected_error'),
        });
      } finally {
        isLoading.value = false;
      }
    };

    const csvCell = (value) => {
      const text = value === null || value === undefined ? '' : String(value);
      if (/[",\n]/.test(text)) {
        return `"${text.replace(/"/g, '""')}"`;
      }
      return text;
    };

    const downloadCsv = (rows, filename) => {
      const csvContent = rows
        .map((row) => row.map(csvCell).join(','))
        .join('\r\n');
      const blob = new Blob(['﻿' + csvContent], {
        type: 'text/csv;charset=utf-8;',
      });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    };

    const exportReport = async () => {
      isExporting.value = true;
      try {
        const response = await matrixHttp.getAll(serializedFilters());
        const data = response.data?.data ?? {};
        const columns = buildDayColumns(data.fromDate, data.toDate);
        const rows = [
          [
            'Employee ID',
            'Nombre',
            'Departamento',
            'Ubicación',
            ...columns.map((day) => `${day.label} ${day.dateLabel}`),
            'Total Horas',
          ],
          ...(data.employees ?? []).map((employee) => [
            employee.employeeId,
            employee.employeeName,
            employee.department || '-',
            employee.location || '-',
            ...columns.map((day) => employee.dates[day.date] || '-'),
            employee.totalHoursWeek,
          ]),
        ];
        downloadCsv(rows, 'matriz-semanal-asistencia.csv');
      } catch (e) {
        showErrorToast({
          title: $t('general.error'),
          message: $t('general.unexpected_error'),
        });
      } finally {
        isExporting.value = false;
      }
    };

    const handleSubmit = () => {
      if (pendingAction.value === 'export') {
        exportReport();
      } else {
        loadMatrix();
      }
    };

    return {
      filters,
      rules,
      pendingAction,
      isLoading,
      isExporting,
      loaded,
      employees,
      dayColumns,
      handleSubmit,
    };
  },
};
</script>

<style src="./weekly-attendance-matrix.scss" lang="scss" scoped></style>
