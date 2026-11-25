import { useState } from 'react'

import { estadisticasApi } from '../../api/estadisticasApi'

import RangoFechas from '../../components/panel/RangoFechas'

import SeccionAccesos from '../../components/panel/SeccionAccesos'

import PaginaListado from '../../components/plantillas/PaginaListado'

import { useCargaApi } from '../../hooks/useCargaApi'

/** Flujo de accesos al sitio (últimos 30 días por defecto). */
export default function AccesosPagina() {
  const [rango, setRango] = useState({ desde: '', hasta: '' })
  const accesos = useCargaApi(() => estadisticasApi.accesos({ desde: rango.desde || undefined, hasta: rango.hasta || undefined }),
    [rango.desde, rango.hasta])

  return (
    <PaginaListado
        titulo="Accesos al sitio"
        descripcion="Visitas y páginas vistas en el período seleccionado."
        accionPrincipal={<RangoFechas desde={rango.desde} hasta={rango.hasta} alCambiar={setRango} />}
        error={accesos.error} cargando={accesos.cargando} actualizando={accesos.actualizando} alReintentar={accesos.recargar}>
      {accesos.datos && <SeccionAccesos datos={accesos.datos} />}
    </PaginaListado>
  )
}
