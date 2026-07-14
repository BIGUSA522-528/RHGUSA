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
  <base-widget
    icon="alarm-fill"
    class="emp-leave-chart"
    :empty="isEmpty"
    empty-text="No hay retardos registrados hoy."
    :loading="isLoading"
    title="Retardos Hoy"
  >
    <template #action>
      <oxd-icon-button
        name="arrow-clockwise"
        title="Refrescar"
        @click="fetchData"
      />
    </template>
    <div
      v-for="employee in employeeList"
      :key="employee.empNumber"
      class="orangehrm-leave-card"
    >
      <div class="orangehrm-leave-card-profile-image">
        <img
          alt="profile picture"
          class="employee-image"
          :src="`../pim/viewPhoto/empNumber/${employee.empNumber}`"
        />
      </div>
      <div class="orangehrm-leave-card-details">
        <oxd-text tag="p" class="orangehrm-leave-card-emp-name">
          {{ employee.employeeName }}
        </oxd-text>
        <oxd-text
          v-for="(incident, index) in employee.incidents"
          :key="index"
          tag="p"
          class="orangehrm-leave-card-leave-details"
        >
          {{ incident.label }}: {{ incident.actual }} (esperada
          {{ incident.expected }})
        </oxd-text>
      </div>
      <oxd-text tag="p" class="orangehrm-leave-card-emp-id">
        {{ employee.employeeId }}
      </oxd-text>
    </div>
  </base-widget>
</template>

<script>
import {OxdIconButton} from '@ohrm/oxd';
import {APIService} from '@/core/util/services/api.service';
import BaseWidget from '@/orangehrmDashboardPlugin/components/BaseWidget.vue';

export default {
  name: 'EmployeesLateTodayWidget',

  components: {
    'base-widget': BaseWidget,
    'oxd-icon-button': OxdIconButton,
  },

  setup() {
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/dashboard/employees/late-today',
    );

    return {
      http,
    };
  },

  data() {
    return {
      employeeList: [],
      isLoading: false,
    };
  },

  computed: {
    isEmpty() {
      return this.employeeList.length === 0;
    },
  },

  beforeMount() {
    this.fetchData();
  },

  methods: {
    fetchData() {
      this.isLoading = true;
      this.http
        .getAll()
        .then((response) => {
          const {data} = response.data;
          this.employeeList = data.employees ?? [];
        })
        .finally(() => {
          this.isLoading = false;
        });
    },
  },
};
</script>

<style src="./employee-on-leave-widget.scss" lang="scss" scoped></style>
