import { Link } from 'react-router-dom'

import { adminApi, permisosApi, sistemaApi } from '../../api/adminApi'
import { estadisticasApi } from '../../api/estadisticasApi'
import Migas from '../../components/layout/Migas'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import Cargando from '../../components/ui/Cargando'

import Insignia from '../../components/ui/Insignia'
import Tarjeta from '../../components/ui/Tarjeta'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import { useCargaApi } from '../../hooks/useCargaApi'

import { useSesion } from '../../hooks/useSesion'
import { formatearFechaHora, formatearNumero, nombreRol } from '../../utils/formato'
import './Admin.css'

const ACCIONES = {
  INICIO_SESION: 'Inicio de sesión', CREAR: 'Crear', MODIFICAR: 'Modificar', ANULAR: 'Anular', ASIGNAR_ROL: 'Asignar rol',
  REVOCAR_ROL: 'Revocar rol', CAMBIO_PRECIO: 'Cambio de precio', APROBAR_SOLICITUD: 'Aprobar solicitud',
  RECHAZAR_SOLICITUD: 'Rechazar solicitud', INVITAR: 'Invitar',
}


/**
 * Hub de Administración (/administracion, guard administracion.panel).
 *
 * Consume exclusivamente endpoints existentes, cada uno condicionado a su permiso exacto:
 * - GET /api/ping                         (público: estado de la API, BD y hora del servidor)
 * - GET /api/admin/usuarios               (permiso: usuarios.gestionar — total, activas, inactivas)
 * - GET /api/admin/permisos               (permiso: permisos.gestionar — roles y conteo de permisos)
 * - GET /api/admin/bitacora               (permiso: bitacora.ver — total y últimos eventos auditados)
 * - GET /api/panel/accesos                (permiso: accesos.ver — visitas e inicios de sesión 30 días)
 *
 * Los enlaces y accesos rápidos hacia cualquier ruta (/admin/*, /panel, /indicadores,
 * /reportes, /operaciones) solo se renderizan si el rol activo posee el permiso exacto.
 */
export default function PanelAdministracionPagina() {
  const { tienePermiso } = useSesion()

  const puede = {
    usuarios: tienePermiso('usuarios.gestionar'),
    permisos: tienePermiso('permisos.gestionar'),
    bitacora: tienePermiso('bitacora.ver'),
    accesos: tienePermiso('accesos.ver'),
    panel: tienePermiso('dashboard.ver'),
    indicadores: tienePermiso('indicadores.ver'),
    reportes: tienePermiso('reportes.ver'),
    operaciones: tienePermiso('operaciones.panel'),
  }

  // Ping del sistema (no exige permiso adicional; verifica API + BD).
  const ping = useCargaApi(() => sistemaApi.ping(), [])

  // Usuarios: total, activas e inactivas obtenidos de paginacion.total (sin inventar métricas).
  const usuariosTotal = useCargaApi(() => (puede.usuarios ? adminApi.usuarios({ pagina: 1 }) : Promise.resolve(null)), [puede.usuarios])
  const usuariosActivos = useCargaApi(() => (puede.usuarios ? adminApi.usuarios({ activo: 1, pagina: 1 }) : Promise.resolve(null)), [puede.usuarios])
  const usuariosInactivos = useCargaApi(() => (puede.usuarios ? adminApi.usuarios({ activo: 0, pagina: 1 }) : Promise.resolve(null)), [puede.usuarios])

  // Matriz de permisos por rol.
  const matriz = useCargaApi(() => (puede.permisos ? permisosApi.matriz() : Promise.resolve(null)), [puede.permisos])

  // Bitácora de auditoría (página 1 para total y últimos registros).
  const bitacora = useCargaApi(() => (puede.bitacora ? estadisticasApi.bitacora({ pagina: 1 }) : Promise.resolve(null)), [puede.bitacora])

  // Estadísticas de accesos al sitio (rango por defecto del backend: últimos 30 días).
  const accesos = useCargaApi(() => (puede.accesos ? estadisticasApi.accesos({}) : Promise.resolve(null)), [puede.accesos])

  const consultas = [
    { c: ping, activa: true },
    { c: usuariosTotal, activa: puede.usuarios },
    { c: usuariosActivos, activa: puede.usuarios },
    { c: usuariosInactivos, activa: puede.usuarios },
    { c: matriz, activa: puede.permisos },
    { c: bitacora, activa: puede.bitacora },
    { c: accesos, activa: puede.accesos },
  ]
  const cargandoInicial = consultas.some(({ c, activa }) => activa && c.cargando && !c.datos)
  const error = consultas.filter(({ activa }) => activa).map(({ c }) => c.error).find(Boolean) ?? null

  const recargarTodo = () => consultas.forEach(({ c, activa }) => activa && c.recargar())


  const datosAccesos = accesos.datos
  const ingresosExitosos = (datosAccesos?.inicios_de_sesion ?? []).reduce((s, d) => s + d.exitosos, 0)
  const ingresosFallidos = (datosAccesos?.inicios_de_sesion ?? []).reduce((s, d) => s + d.fallidos, 0)
  const ultimosRegistros = (bitacora.datos?.registros ?? []).slice(0, 5)
  const rolesMatriz = matriz.datos?.roles ?? []
  const catalogoPermisos = matriz.datos?.permisos ?? []

  return (
    <div className="admin">
      <Migas items={[{ etiqueta: 'Inicio', ruta: '/' }, { etiqueta: 'Administración' }]} />

      <EncabezadoPagina
        titulo="Administración"
        descripcion="Estado del sistema, cuentas y actividad de auditoría."
        accion={<Boton variante="secundario" onClick={recargarTodo}>Actualizar datos</Boton>}
      />

      {error && <Alerta tipo="error">{error}</Alerta>}
      {cargandoInicial && <Cargando texto="Cargando panel de administración…" />}

      <div className="admin__hub-rejilla">
        <Tarjeta titulo="Estado del sistema">
          {ping.datos ? (
            <div className="admin__hub-bloque">
              <div className="admin__hub-estado">
                <Insignia tono={ping.datos.estado === 'ok' ? 'exito' : 'error'}>
                  {ping.datos.estado === 'ok' ? 'Operativo (API + BD)' : ping.datos.estado}
                </Insignia>
                <span className="admin__resumen">{ping.datos.sistema}</span>
              </div>
              <dl className="admin__hub-metricas">
                <div>
                  <dt>Hora del servidor</dt>
                  <dd>{formatearFechaHora(ping.datos.hora_servidor)}</dd>
                </div>
              </dl>
            </div>
          ) : (
            <p className="admin__resumen">Verificando conexión con el servidor…</p>
          )}
        </Tarjeta>

        {puede.usuarios && usuariosTotal.datos && usuariosActivos.datos && usuariosInactivos.datos && (
          <Tarjeta titulo="Cuentas de usuario">
            <div className="admin__hub-bloque">
              <dl className="admin__hub-metricas">
                <div>
                  <dt>Total registradas</dt>
                  <dd className="admin__hub-cifra">{formatearNumero(usuariosTotal.datos?.paginacion?.total ?? 0)}</dd>
                </div>
                <div>
                  <dt>Activas</dt>
                  <dd className="admin__hub-cifra">{formatearNumero(usuariosActivos.datos?.paginacion?.total ?? 0)}</dd>
                </div>
                <div>
                  <dt>Inactivas</dt>
                  <dd className="admin__hub-cifra">{formatearNumero(usuariosInactivos.datos?.paginacion?.total ?? 0)}</dd>
                </div>
              </dl>
              <Link to="/admin/usuarios" className="boton boton--secundario">Gestionar usuarios y roles</Link>
            </div>
          </Tarjeta>
        )}

        {puede.permisos && matriz.datos && (
          <Tarjeta titulo="Permisos y menú (RBAC)">
            <div className="admin__hub-bloque">
              <p className="admin__resumen">
                {formatearNumero(catalogoPermisos.length)} permisos configurables en {formatearNumero(rolesMatriz.length)} roles.
              </p>
              <ul className="admin__hub-lista">
                {rolesMatriz.map((r) => (
                  <li key={r.codigo} className="admin__hub-fila">
                    <span>{nombreRol(r.codigo)}</span>
                    <Insignia tono="info">{r.permisos.length} permisos</Insignia>
                  </li>
                ))}
              </ul>
              <Link to="/admin/permisos" className="boton boton--secundario">Configurar permisos y menú</Link>
            </div>
          </Tarjeta>
        )}

        {puede.accesos && accesos.datos && (
          <Tarjeta titulo="Accesos al sitio (últimos 30 días)">
            <div className="admin__hub-bloque">
              <dl className="admin__hub-metricas">
                <div>
                  <dt>Visitas a páginas</dt>
                  <dd className="admin__hub-cifra">{formatearNumero(datosAccesos?.total_visitas ?? 0)}</dd>
                </div>
                <div>
                  <dt>Ingresos correctos</dt>
                  <dd className="admin__hub-cifra">{formatearNumero(ingresosExitosos)}</dd>
                </div>
                <div>
                  <dt>Intentos fallidos</dt>
                  <dd className="admin__hub-cifra">{formatearNumero(ingresosFallidos)}</dd>
                </div>
              </dl>
              {(datosAccesos?.ips_con_mas_fallos?.length ?? 0) > 0 && (
                <p className="admin__resumen">
                  IP con más fallos: <strong>{datosAccesos.ips_con_mas_fallos[0].ip}</strong> ({datosAccesos.ips_con_mas_fallos[0].fallidos} intentos)
                </p>
              )}
              <Link to="/admin/accesos" className="boton boton--secundario">Ver estadísticas de acceso</Link>
            </div>
          </Tarjeta>
        )}
      </div>

      {puede.bitacora && bitacora.datos && (
        <Tarjeta titulo="Bitácora de auditoría reciente">
          <div className="admin__hub-bloque">
            <div className="admin__hub-cabecera-seccion">
              <p className="admin__resumen">
                {formatearNumero(bitacora.datos?.paginacion?.total ?? 0)} eventos registrados en total. Mostrando los 5 más recientes.
              </p>
              <Link to="/admin/bitacora" className="boton boton--secundario">Abrir bitácora completa</Link>
            </div>
            {ultimosRegistros.length === 0 ? (
              <p className="admin__resumen">Aún no hay registros en la bitácora.</p>
            ) : (
              <div className="admin__tabla-contenedor" tabIndex={0} role="region" aria-label="Bitácora reciente">
                <table className="admin__tabla">
                  <thead>
                    <tr>
                      <th scope="col">Fecha</th>
                      <th scope="col">Usuario</th>
                      <th scope="col">Acción</th>
                      <th scope="col">Entidad</th>
                      <th scope="col">IP</th>
                    </tr>
                  </thead>
                  <tbody>
                    {ultimosRegistros.map((r) => (
                      <tr key={r.id}>
                        <td>{formatearFechaHora(r.fecha)}</td>
                        <td>{r.usuario ?? 'Visitante'}</td>
                        <td>
                          {r.accion === 'INICIO_SESION' ? (
                            <Insignia tono={r.detalle?.resultado === 'EXITO' ? 'exito' : 'error'}>
                              {ACCIONES[r.accion]}
                            </Insignia>
                          ) : (
                            ACCIONES[r.accion] ?? r.accion
                          )}
                        </td>
                        <td>{r.entidad === 'ACCESO_WEB' ? 'Página web' : r.entidad}{r.entidad_id ? ` #${r.entidad_id}` : ''}</td>
                        <td>{r.ip ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </Tarjeta>
      )}



      {(puede.panel || puede.indicadores || puede.reportes || puede.operaciones) && (
        <Tarjeta titulo="Supervisión y paneles de negocio">
          <div className="admin__hub-botones">
            {puede.operaciones && <Link to="/operaciones" className="boton boton--secundario">Bandeja de operaciones</Link>}
            {puede.panel && <Link to="/panel" className="boton boton--secundario">Panel de negocio</Link>}
            {puede.indicadores && <Link to="/indicadores" className="boton boton--secundario">Indicadores</Link>}
            {puede.reportes && <Link to="/reportes" className="boton boton--secundario">Reportes descargables</Link>}
          </div>
        </Tarjeta>
      )}
    </div>
  )
}
