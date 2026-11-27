import { productoresApi } from '../../api/productoresApi'
import Migas from '../../components/layout/Migas'
import SolicitudProductor from '../../components/cliente/SolicitudProductor'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import Alerta from '../../components/ui/Alerta'
import Cargando from '../../components/ui/Cargando'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import './MiCuenta.css'

/**
 * /mi-solicitud (permiso mi_cuenta.ver): seguimiento de la solicitud para
 * afiliarse como productor, separado del perfil para que ese proceso tenga
 * un espacio propio con su estado claro.
 */
export default function MiSolicitudPagina() {
  const { sesion } = useSesion() // no hay cargando aquí: RutaProtegida ya garantizó sesión
  const solicitud = useCargaApi(() => productoresApi.estadoSolicitud(), [])
  const comunidades = useCargaApi(() => productoresApi.comunidadesDisponibles(), [])

  const cargando = (solicitud.cargando && !solicitud.datos) || (comunidades.cargando && !comunidades.datos)
  // Afiliación aprobada si el registro lo dice O si el rol PRODUCTOR ya está
  // asignado: una persona puede actuar como CLIENTE y ser productora además.
  const aprobada = solicitud.datos?.solicitud?.estado === 'APROBADA'
    || sesion.roles.some((rol) => rol.codigo === 'PRODUCTOR')

  return (
    <div className="mi-cuenta">
      <Migas items={[{ etiqueta: 'Cuenta', ruta: '/mi-cuenta' }, { etiqueta: 'Solicitud de productor' }]} />
      <EncabezadoPagina
        titulo="Solicitud de productor"
        descripcion="Sigue el estado de tu solicitud para unirte a FEDER como productor."
      />

      {cargando && !aprobada && <Cargando texto="Recuperando tu solicitud…" />}
      {solicitud.error && <Alerta tipo="error">{solicitud.error}</Alerta>}

      {!cargando && !solicitud.error && (
        aprobada ? (
          // Confirmación completa del SolicitudProductor (sin formulario de reenvío).
          // El estado se expande primero y 'APROBADA' al final: nunca puede quedar
          // pisado por un estado viejo del registro.
          <SolicitudProductor estado={{ ...solicitud.datos?.solicitud, estado: 'APROBADA' }} />
        ) : (
          <SolicitudProductor
            estado={solicitud.datos?.solicitud}
            comunidades={comunidades.datos?.comunidades || []}
            alEnviar={() => solicitud.recargar()}
          />
        )
      )}
    </div>
  )
}
